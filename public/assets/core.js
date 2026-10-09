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
   t('<id>.<key>'), or with Office.scope('<id>') as a shortcut.
   Its state comes through Office.loadState(id, {fresh, part}, took): at once as kept, the agent's new look
   following on its own page (show first, then look); actions on what the page shows ask Office.freshState(id).
   Its places (sections, tiles, terms …) for the search: Office.places(id, [...]) beside Office.desk(), the
   elements marked with Office.place(anchor, node) — see «places and the search» below; what its state holds
   worth finding (findings, entries, shares …): Office.placesFrom(id, (state, part) => [...]) — «items» there. */
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
/**
 * size-switch: where a box fixed inside #sso stands is said in the office's own px — under the text-size switch's zoom
 * (size-switch.css) not the window's. w, h: the window in those px; k: what a getBoundingClientRect or a mouse position
 * is divided by to get there (Chrome and Firefox give them in the window's px, Safari already in the element's own).
 * Without zoom the window as it is and 1 — the menu, the tips and the search palette place themselves with it.
 */
function fixedSpace() {
  const z = (typeof getComputedStyle === 'function' && parseFloat(getComputedStyle(ROOT).zoom)) || 1;     // (the tests' DOM has none)
  if (z === 1) return { w: window.innerWidth, h: window.innerHeight, k: 1 };
  const ratio = ROOT.offsetWidth ? ROOT.getBoundingClientRect().width / ROOT.offsetWidth : z;
  return { w: window.innerWidth / z, h: window.innerHeight / z, k: Math.abs(ratio - 1) < Math.abs(ratio - z) ? 1 : z };
}
Office.fixedSpace = fixedSpace;

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

// Unraid's own labels in the texts, ⟦Settings⟧ → ⟦User Utilities⟧, as Unraid shows them in the language it runs in
// (CONFIG.unraid_lang) — whatever the office speaks: CONFIG.unraid_words from lang/unraid/<code>.json (src/words.php);
// English, and a label without an entry, stay as written
const UNRAID_WORDS = CONFIG.unraid_words && typeof CONFIG.unraid_words === 'object' ? CONFIG.unraid_words : {};
const unraidWords = (s) => (s.indexOf('⟦') < 0 ? s
  : s.replace(/⟦([^⟦⟧]+)⟧/g, (m, label) => (typeof UNRAID_WORDS[label] === 'string' && UNRAID_WORDS[label]) || label));

/** t('key', {n: 3, name: 'x'}) — plurals as {one, other, …}, placeholders as {name}, Unraid's labels in Unraid's words */
function t(key, params) {
  let s = Office.strings[key];
  if (s === undefined || s === null) return key;   // visible on purpose: missing strings get noticed
  if (typeof s === 'object') {
    const n = params && typeof params.n === 'number' ? params.n : 0;
    let cat = 'other';
    try { cat = plural.select(n); } catch (e) { /* unknown locale */ }
    s = s[n === 0 && s.zero !== undefined ? 'zero' : cat] ?? s.other ?? '';
  }
  s = unraidWords(String(s));
  if (!params) return s;
  return s.replace(/\{(\w+)\}/g, (m, k) => {
    if (params[k] === undefined || params[k] === null) return m;
    return typeof params[k] === 'number' ? Office.fmt.number(params[k]) : String(params[k]);
  });
}
Office.t = t;
Office.scope = (desk) => (key, params) => t(`${desk}.${key}`, params);
Office.has = (key) => Office.strings[key] !== undefined;

/**
 * The browser's language: the first of navigator.languages the office speaks (de-CH → de), else English.
 * Unraid's own language only decides how its labels read (CONFIG.unraid_words). The Dashboard tile (src/dashboard.php)
 * and the server (officeBrowserLang(), Accept-Language) pick by the same rule.
 */
function browserLanguage(codes) {
  const tags = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''];
  for (const tag of tags) {
    const want = String(tag || '').toLowerCase();
    const exact = codes.find((c) => c.toLowerCase() === want);
    if (exact) return exact;
    if (codes.includes(want.split('-')[0])) return want.split('-')[0];
  }
  return 'en';
}

/** The language chosen for this browser (⋯ → Language), else the browser's */
function pickLanguage() {
  const codes = CONFIG.languages.map((l) => l.code);
  const saved = Office.store('lang');
  if (saved && codes.includes(saved)) return saved;
  return browserLanguage(codes);
}

/**
 * The notifications (made on the server, no browser there) speak the language the office was last used in: the page
 * tells the server when it shows another one than the server keeps (data/office/lang.json, CONFIG.lang_seen).
 */
function rememberLanguage() {
  if (CONFIG.lang_seen === Office.lang) return;
  CONFIG.lang_seen = Office.lang;
  Office.api.post('office.lang', { lang: Office.lang });
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
const QUIET = /\.(read|output|log|estimate|detail|measure|where_refresh|where_measure|staff_order|supporter_code|supporter_claim|lang)$/;
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
  /**
   * get({a: 'part', desk, part}) — never throws (review 2026-10-09): a network failure or an answer that isn't JSON
   * answers {ok: false, error: {key: 'offline'}} like post(), so a poll (a restore's or drill's job) keeps going instead of
   * stopping for good on one blip. «No connection to the server.» is said once per outage, not on every poll.
   */
  async get(params) {
    let j;
    try {
      const r = await fetch(API + '?' + new URLSearchParams(params), { cache: 'no-store' });
      if (loggedOut(r)) return { ok: false, error: { key: 'logged_out' } };
      j = await r.json();
    } catch (e) {
      j = null;
    }
    if (!j || typeof j !== 'object') {
      offline(true);
      return { ok: false, error: { key: 'offline' } };
    }
    offline(false);
    if (j.agent) Office.setAgent(j.agent);
    return j;
  },
  /** post('snapshot.delete', {ids}) — the agent answers {ok, …} or {ok:false, error:{key, params}} */
  async post(action, data) {
    return postBusy(action, data);
  },
};

/** The connection lost (on) or back: said once when it goes, quiet while it stays away */
let offlineSaid = false;
function offline(on) {
  if (on && !offlineSaid) Office.toast(t('errors.offline'), true);
  offlineSaid = on;
}
/** How long a poll waits after `fails` failed asks in a row: its own pace, doubled per failure, at most 30 s */
Office.pollDelay = function pollDelay(base, fails) {
  return fails > 0 ? Math.min(base * 2 ** Math.min(fails, 6), 30000) : base;
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
  // the agent restarted while this waited: nothing it was asked before is coming — no desk keeps the wave up for it
  if (j.error && j.error.key === 'agent_restarted') [...busyHolds.keys()].forEach((key) => Office.busy(key, false));
  j.desk = action.split('.')[0];
  return j;
}

// ------------------------------------------------------------------ states: show first, then look
/*
 * A desk's state (or a part of it) the quick way (src/api.php apiLook(), Benj 2026-10-07): the server answers at once
 * with what it keeps — `stale` when older than the desk's refresh_after, `refreshing` while the agent looks again in the
 * background; the page then asks once more (`wait`) and shows the new state when it is there. Who asks how:
 *   the desk whose page is shown   at once, the new look follows (its mount, its poll)
 *   anyone else                    as kept, never a look (the reception's cards, the badges every page shows)
 *   fresh                          wait for a new look (the Tour / «Look again» buttons, live views)
 * The same question while one is on its way gets the same answer (the reception and started() ask together: each desk
 * once). took(j, later) gets every answer worth showing — j as from Office.api.get (j.state, or j.part); later = the
 * new look that followed, handed over only while the user isn't in the middle of something (calm()) and kept in place.
 * An answer older than one already shown is dropped. Actions on what the page shows ask Office.freshState() first.
 */
const asked = new Map();            // the same GET on its way: one request
function getOnce(params) {
  const key = new URLSearchParams(params).toString();
  if (!asked.has(key)) {
    asked.set(key, Office.api.get(params).catch(() => ({ ok: false, error: { key: 'offline' } })).finally(() => asked.delete(key)));
  }
  return asked.get(key);
}

const looks = new Map();            // 'desk' or 'desk/part' -> { time, age, at, after, refreshing, pending, since, took }
const lookKey = (desk, part) => (part ? `${desk}/${part}` : desk);
/** How old the state is now, by the server's clock (its age when answered plus the time since), or null */
const lookAge = (look) => (typeof look.age === 'number' ? look.age + (Date.now() - look.at) / 1000 : look.age === null ? Infinity : null);
const lookStale = (look) => typeof look.after === 'number' && lookAge(look) > look.after;

/** Note an answer; 'older' (than what was shown: drop it), 'same' (that very state), 'new', or null (no state answer) */
function noteLook(look, j) {
  if (!j || !j.ok) return null;
  const data = 'part' in j ? j.part : j.state;
  const time = data && typeof data.time === 'number' ? data.time : null;
  if (time !== null && typeof look.time === 'number' && time < look.time) return 'older';
  const same = time !== null && time === look.time;
  look.time = time;
  if ('age' in j) { look.age = j.age; look.at = Date.now(); look.after = j.refresh_after; }
  look.refreshing = !!j.refreshing;
  return same ? 'same' : 'new';
}

Office.loadState = async function loadState(desk, opts, took) {
  const { fresh, part } = opts || {};
  const key = lookKey(desk, part);
  const look = looks.get(key) || {};
  looks.set(key, look);
  look.took = took;
  look.part = part || null;
  const base = part ? { a: 'part', desk, part } : { a: 'state', desk };
  const how = fresh ? 'fresh' : Office.current && Office.current.id === desk ? '' : 'stored';
  const j = await getOnce(how ? { ...base, [how]: 1 } : base);
  const seen = noteLook(look, j);
  // the very state the page has, and the new look on its way: nothing to draw now. A render that throws (a kept
  // state in an older version's shape, right after an update) doesn't stop the new look: it is asked for all the
  // same and heals the page at once; the error still reaches the caller (and so the console) once, afterwards
  let failed = null;
  if (seen !== 'older' && !(seen === 'same' && j.refreshing)) {
    try { took(j, false); } catch (e) { failed = e; }
  }
  if (j.ok && j.refreshing && !look.pending) followLook(desk, look, base);
  stateItems(desk, part, j, seen);          // the search's items from it (when the page is idle)
  paintAsOf(desk);
  if (failed) throw failed;
  return j;
};

/** The agent looks again: ask for that look (`wait`) and hand it over once the user is calm */
function followLook(desk, look, base) {
  look.since = Date.now();
  const p = getOnce({ ...base, wait: 1 });
  look.pending = p;
  setTimeout(() => paintAsOf(desk), ASOF_PATIENCE + 50);
  p.then((k) => whenCalm(() => tookLater(desk, look, p, k)));
}
function tookLater(desk, look, p, k) {
  if (look.pending !== p) return;          // handed over already (Office.freshState())
  look.pending = null;
  const seen = noteLook(look, k);
  if (seen !== 'older' && look.took) {
    if (Office.current && Office.current.id === desk) Office.keepInPlace(null, () => look.took(k, true));
    else look.took(k, true);
  }
  stateItems(desk, look.part, k, seen);
  paintAsOf(desk);
}

/**
 * Before an action on what the page shows (a selection to delete, a plan's targets …): the state fresh — the look under
 * way handed over at once, a stale one looked at again (fresh). False (and said) when no fresh look could be had: never
 * act on a stale list.
 */
Office.freshState = async function freshState(desk, part) {
  const look = looks.get(lookKey(desk, part));
  if (!look || (!look.pending && !lookStale(look))) return true;     // never loaded through Office.loadState(), or fresh
  Office.busy('look.' + desk, true);       // the wave while it takes more than a moment
  try {
    if (look.pending) {                    // the look under way (a failed one isn't asked for again at once)
      const p = look.pending;
      tookLater(desk, look, p, await p);
    } else if (look.took) {
      await Office.loadState(desk, { fresh: true, part }, look.took);
    }
  } finally {
    Office.busy('look.' + desk, false);
  }
  if (!lookStale(look)) return true;
  Office.toast(t('office.stale_refused', { name: t(`${desk}.name`) }), true);
  return false;
};

/**
 * Is the user in the middle of something a new picture would disturb? A dialog or menu open, a mouse button or finger
 * down, or typing in a field of the page that its next render would build anew (fields built once, like a desk's
 * filter, carry data-keep and don't count).
 */
const TYPING = 'textarea, select, input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]):not([type=reset]):not([type=file]):not([type=range]):not([type=color])';
let pressed = 0;
function calm() {
  if (Office.dialogOpen() || Office.menuOpen() || Office.paletteOpen() || (pressed && Date.now() - pressed < 3000)) return false;
  const f = document.activeElement;
  return !(f && f.matches && f.matches(TYPING) && !f.closest('[data-keep]') && $('#sso-desk').contains(f));
}
function whenCalm(fn) {
  if (calm()) fn(); else setTimeout(() => whenCalm(fn), 250);
}
Office.calm = calm;

/**
 * «As of …»: a state older than the desk looks after shows when it was (the server's clock) — quietly, on the reception's
 * card when older than ASOF_RECEPTION (or the desk's refresh_after, if longer), under the desk's head while its page
 * shows a stale state and no new look came within ASOF_PATIENCE.
 */
const ASOF_RECEPTION = 900;
const ASOF_PATIENCE = 1500;
const asofNodes = new Map();       // desk -> the line under its head
/** The oldest state the desk has shown (its own and its parts' that are looked after), or null */
function deskLook(desk) {
  let worst = null;
  for (const [key, look] of looks) {
    if ((key === desk || key.startsWith(desk + '/')) && typeof look.after === 'number' && (!worst || lookAge(look) - look.after > lookAge(worst) - worst.after)) worst = look;
  }
  return worst;
}
function asOfText(look) {
  const when = Date.now() / 1000 - lookAge(look);
  const today = Office.fmt.dayKey(when) === Office.fmt.dayKey(Date.now() / 1000);
  return t('office.as_of', { when: today ? Office.fmt.time(when) : Office.fmt.date(when, true) });
}
/** The reception's quiet line for a desk's card, or null */
function receptionAsOf(desk) {
  const look = deskLook(desk);
  if (!look || !isFinite(lookAge(look)) || lookAge(look) <= Math.max(look.after, ASOF_RECEPTION)) return null;
  const li = el('li', 'role', asOfText(look));
  li.title = t('office.as_of_tip');
  return li;
}
function paintAsOf(desk, node) {
  node = node || asofNodes.get(desk);
  if (!node) return;
  const look = deskLook(desk);
  const waiting = look && look.pending && Date.now() - look.since < ASOF_PATIENCE;
  const show = !!look && lookStale(look) && isFinite(lookAge(look)) && !waiting;
  node.hidden = !show;
  node.textContent = show ? asOfText(look) : '';
  node.title = show ? t('office.as_of_tip') : '';
}

/** Text for an error {key, params} — desk-specific first, then the office's */
// the last error each desk showed in this page session (memory only): «Report a problem…» offers it as a part
Office.lastError = {};
Office.errorText = function errorText(error, desk) {
  if (!error) return t('errors.internal', { detail: '?' });
  const params = error.params || {};
  if (typeof error.key === 'string' && !/^report_/.test(error.key)) {
    Office.lastError[desk || 'office'] = { key: error.key, params, at: Math.floor(Date.now() / 1000) };
  }
  if (desk && Office.has(`${desk}.errors.${error.key}`)) return t(`${desk}.errors.${error.key}`, params);
  if (Office.has(`errors.${error.key}`)) return t(`errors.${error.key}`, params);
  return t('errors.unknown', { key: error.key, detail: params.detail || '' });
};

// ------------------------------------------------------------------ agent status
Office.setAgent = function setAgent(info) {
  Office.agent = info || { running: false };
  // the array isn't started: the night watchman's night shift keeps watch (src/mailbox.php officeNightShift(), RAM only)
  const night = !Office.agent.running && Office.agent.night ? Office.agent.night : null;
  const dot = $('#sso-dot');
  dot.className = 'dot ' + (Office.agent.running ? 'on' : night ? 'night' : 'off');
  dot.title = Office.agent.running ? t('agent.running', { version: Office.agent.version || '?' }) : night ? nightText(night) : t('agent.away');
  // a word next to the dot: what runs, or what doesn't
  const state = $('#sso-state');
  const no = Office.agent.no_data;
  state.className = 'agent-state ' + (Office.agent.running ? 'on' : night ? 'night' : 'off');
  $('#sso-state-label').textContent = t(Office.agent.running ? 'agent.label_on' : night ? 'agent.label_night'
    : no ? (no.array !== 'Started' ? 'agent.label_array' : 'agent.label_no_data') : 'agent.label_off');
  state.title = dot.title;
  dot.removeAttribute('title');
  const notice = $('#sso-notice');
  if (Office.agent.running) {
    if (notice.dataset.kind === 'agent') { notice.hidden = true; notice.dataset.kind = ''; }
  } else if (night) {
    // calm: nothing is wrong, the office waits for the array and he keeps watch meanwhile
    notice.innerHTML = '';
    notice.className = 'notice info night';
    notice.dataset.kind = 'agent';
    const text = el('span');
    text.append(el('strong', '', t('agent.array_stopped_title')), ' ', nightText(night));
    notice.append(Office.deskIcon('watchman'), text);
    notice.hidden = false;
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
  updateNotice();
};

/*
 * The office was updated while this page was open (Unraid's plugin manager swaps the plugin's folder and starts the new
 * agent): every answer that carries the messenger (agentInfo(): its heartbeat, with its version) tells this page — when
 * the running agent's version isn't the page's own (CONFIG.version), the old page talks to the new office. One calm line
 * under the top line says so, with a button that reloads — never a reload of its own (a dialog may be half filled in).
 * Once per version: «Later» puts it away for that version (kept in this browser), a newer one brings it back.
 */
let updateShown = '';
function updateNotice() {
  const v = Office.agent && Office.agent.running ? Office.agent.version : null;
  if (typeof v !== 'string' || v === '' || !CONFIG.version || v === CONFIG.version || v === updateShown || Office.store('update.later') === v) return;
  updateShown = v;
  let node = $('#sso-update');
  if (!node) {
    node = el('p', 'notice info update');
    node.id = 'sso-update';
    $('#sso-notice').after(node);
  }
  node.innerHTML = '';
  const reload = el('button', 'btn small', t('office.updated_reload'));
  reload.type = 'button';
  reload.onclick = () => location.reload();
  const later = el('button', 'btn small plain', t('office.updated_later'));
  later.type = 'button';
  later.onclick = () => { node.hidden = true; Office.store('update.later', v); };
  node.append(el('span', '', t('office.updated', { version: v })), reload, later);
  node.hidden = false;
}

/** "The night watchman has been on night shift since 09:18 (12 rounds, 1 new entry) …" — {since, rounds, new} */
function nightText(night) {
  const today = Office.fmt.dayKey(night.since) === Office.fmt.dayKey(Date.now() / 1000);
  const facts = [];
  if (night.rounds > 0) facts.push(t('agent.night_rounds', { n: night.rounds }));
  facts.push(night.new > 0 ? t('agent.night_new', { n: night.new }) : t('agent.night_nothing'));
  return t('agent.night_text', { since: today ? Office.fmt.time(night.since) : Office.fmt.date(night.since), facts: facts.join(', ') });
}

/**
 * Without the data folder (the array stopped) the desks have nothing to show — their state and who works here lie
 * in it. The reception says why (the notice) and looks again every minute; once the folder is back a page that began
 * without it loads anew (who works here was unknown), the reception shows its desks again.
 */
const noData = () => !Office.agent.running && !!Office.agent.no_data;
const startedWithoutData = () => !!(CONFIG.agent && CONFIG.agent.no_data && !CONFIG.agent.running);
async function agentLook() {
  const j = await Office.api.get({ a: 'agent' }).catch(() => null);
  if (!j || !j.ok || !j.agent || j.agent.no_data) return;
  if (startedWithoutData()) location.reload();
  else if (!Office.current && !$('#sso-desk .reception')) route();
}

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

  const s = fixedSpace();       // the office's own px (the text-size switch's zoom)
  let x = event.clientX / s.k, y = event.clientY / s.k;
  if ((!x && !y) || event.detail === 0) {
    const r = event.currentTarget.getBoundingClientRect();
    x = r.right / s.k - 200; y = r.bottom / s.k + 4;
  }
  x = Math.min(x, s.w - box.offsetWidth - 8);
  y = Math.min(y, s.h - box.offsetHeight - 8);
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

/**
 * The staff's order (src/staff.php, data/office/staff.json "order", the same in every browser): every desk's id — the
 * team lead first, then the order the user set at the reception («Change the order»), then the rest by desk.json's
 * order. The reception's cards, the tabs and the team lead's «The team» follow it.
 */
let staffOrder = Array.isArray(CONFIG.staff_order) ? CONFIG.staff_order.slice() : [];
/** A desk's place in the staff's order (one it doesn't name: after all of them, by desk.json's order) */
Office.deskRank = function deskRank(id) {
  const i = staffOrder.indexOf(id);
  if (i >= 0) return i;
  const d = Office.desks.get(id) || CONFIG.desks.find((x) => x.id === id) || {};
  return staffOrder.length + 1000 + (d.order ?? 0);
};
/** The desks that work here, in the staff's order */
Office.staffInOrder = () => [...Office.desks.values()].filter((d) => d.hired).sort((a, b) => Office.deskRank(a.id) - Office.deskRank(b.id));

/**
 * Desks that went into another one: their old addresses lead to the part of the page that took them over
 * (2026-10: Ms. Whereabouts' work is Ms. Dustdevil's «Where is what»). The address as it should be, or null.
 */
const MOVED_DESKS = { whereabouts: 'cleanup/where' };
function movedDesk(hash, known) {
  const id = (hash.replace(/^#\/?/, '') || '').split('/')[0];
  return Object.prototype.hasOwnProperty.call(MOVED_DESKS, id) && !known(id) ? `#/${MOVED_DESKS[id]}` : null;
}

let routeGen = 0;                   // counts the pages drawn: Office.reveal() waits for the one it asked for
function route() {
  routeGen++;
  if (Office.paletteOpen()) closePalette(false);
  const moved = movedDesk(location.hash, (id) => Office.desks.has(id));
  if (moved) history.replaceState(null, '', moved);
  const parts = (location.hash.replace(/^#\/?/, '') || '').split('/');
  const id = parts[0] || '';
  const sub = parts.slice(1).join('/');
  let next = Office.desks.get(id) || null;
  if (noData()) next = null;               // the array stopped: the reception says why (the address stays for later)
  else if (next && !next.hired) {          // not working here (yet): the caretaker knows who could come
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
  // without the data folder (the array stopped) nobody's state is there: only the reception, which says why
  if (!noData()) for (const d of Office.staffInOrder()) add(`#/${d.id}`, d.id, t(`${d.id}.name`), Office.current === d);
  // on a phone the tabs scroll sideways in their row: the current one in view (only the row moves, not the page)
  if (current && nav.scrollWidth > nav.clientWidth) {
    nav.scrollLeft = Math.max(0, current.offsetLeft - nav.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2);
  }
}

/**
 * "How to read this page", folded out under a desk's head — closed unless the
 * user opened it (remembered per desk). items: [[term (text or node), text], …]
 * A term the desk lists as a place of kind help (Office.places()) gets its anchor here, found by its words —
 * desk may be '<id>-<page>' (a page of the desk, #/<id>/<page>: its own folding, the desk's places of that route).
 */
Office.pageHelp = function pageHelp(desk, items) {
  const det = el('details', 'page-help');
  det.open = Office.store(desk + '.help') === '1';
  det.ontoggle = () => Office.store(desk + '.help', det.open ? '1' : null);
  det.appendChild(el('summary', '', t('common.page_help')));
  const dl = el('dl', 'page-help-list');
  const [id, page = ''] = String(desk).split('-');
  const marks = new Map((placeLists.get(id) || []).filter((p) => p.kind === 'help' && p.anchor && (p.route.split('/')[2] || '') === page)
    .map((p) => [foldText(t(`${id}.${p.key}`)), p.anchor]));
  items.forEach(([term, text]) => {
    const dt = el('dt');
    if (term instanceof Node) dt.appendChild(term); else dt.textContent = term;
    const place = marks.get(foldText(dt.textContent));
    if (place) dt.dataset.place = place;
    dl.append(dt, el('dd', '', text));
  });
  det.appendChild(dl);
  return det;
};

/**
 * A section heading: a title bar (inside Unraid like its own) with the extras
 * (counts, buttons) on its right, the explanation right underneath. An extra {place: '<anchor>'} is no node: it marks
 * the heading as the place the search jumps to (Office.places()).
 */
Office.sectionHead = function sectionHead(title, sub, ...right) {
  const head = el('div', 'section-head');
  const bar = el('div', 'section-bar');
  bar.appendChild(el('h2', '', title));
  right.filter(Boolean).forEach((x) => {
    if (typeof x === 'object' && !x.nodeType && typeof x.place === 'string') head.dataset.place = x.place;
    else bar.appendChild(x);
  });
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
  const s = fixedSpace();       // the office's own px (the text-size switch's zoom)
  const b = node.getBoundingClientRect();
  const r = { left: b.left / s.k, top: b.top / s.k, bottom: b.bottom / s.k };
  const w = tipBox.offsetWidth, h = tipBox.offsetHeight;
  const left = Math.max(8, Math.min(r.left, s.w - w - 8));
  const below = r.bottom + 6 + h <= s.h - 8;
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
    // a control INSIDE a node that explains itself (a row with data-tip holding buttons) keeps its click — only a
    // click on the explaining node itself (or its plain text) opens the explanation (1.36.1: the night watchman's
    // «I know, thanks» on a posture tip never fired — its row carried data-tip)
    const control = e.target.closest && e.target.closest('button, a, label, input, select, textarea, summary');
    if (!node || ownClick(node) || (control && control !== node && node.contains(control))) {
      if (tipPinned && !(tipBox && tipBox.contains(e.target))) hideTip();
      return;
    }
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
/**
 * "Let X go?" — with what keeps running (the desk's fire_note); used by the caretaker and every desk's ⋯ menu. A desk
 * may add to it (its `letGo(box)`, Mr. Backupsy's «Also clear away what he kept here»): it fills the box and answers
 * {bind(button), before(), done(result)} — bind gets the «Let go» button, before() runs while the desk is still hired
 * (an unhired desk gets no write actions), the desk is let go whatever it answered or threw, and done() gets its answer.
 */
Office.fireDialog = function fireDialog(id, after) {
  const name = t(`${id}.name`);
  const box = el('div');
  box.appendChild(el('p', '', t('office.fire_text', { name })));
  if (Office.has(`${id}.fire_note`)) box.appendChild(el('p', 'callout', t(`${id}.fire_note`)));
  const desk = Office.desks.get(id);
  let extra = null;
  try { extra = desk && typeof desk.letGo === 'function' ? desk.letGo(box) : null; } catch (e) { extra = null; }
  const d = Office.dialog({
    title: t('office.fire_title', { name }),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.fire'), kind: 'danger', act: async () => {
        let result = null;
        if (extra && extra.before) {
          try { result = await extra.before(); } catch (e) { result = { ok: false, error: { key: 'internal', params: { detail: String(e && e.message || e) } } }; }
        }
        if (!await Office.fire(id)) return false;
        Office.toast(t('office.fired', { name }));
        if (after) after();
        else if (Office.current && Office.current.id === id) Office.go('#/');
        if (extra && extra.done && result) setTimeout(() => extra.done(result), 0);    // after this dialog closed
        return true;
      } },
    ],
  });
  if (extra && extra.bind) extra.bind(d.buttons[1]);
  return d;
};
function staffChanged(hired) {
  for (const d of CONFIG.desks) d.hired = hired.includes(d.id);
  for (const d of Office.desks.values()) d.hired = hired.includes(d.id);
  tabs();
}

/** "If they do their jobs well, they'd be glad of a tip!" — after hiring, unless switched off or thanked already (a supporter key) */
function tipJar(ids) {
  if (Office.store('tip.never') === '1' || Office.supporter().state === 'valid') return;
  Office.tipJar(ids);
}
/** The tip jar itself — after hiring (ids), or asked for (no ids: the button at the caretaker's team); with a valid supporter key it says thank you */
Office.tipJar = function openTipJar(ids) {
  const sup = Office.supporter();
  const thanked = sup.state === 'valid';
  // the support page's link carries a one-time code (src/supporter.php): the agent later asks the page for the key
  // the tip made — taken now, so the click opens the page at once (a window opened after a wait is blocked)
  let code = null;
  if (supportPage(sup) && sup.id) {
    Office.api.post('office.supporter_code', {}).then((j) => { if (j && j.ok && typeof j.code === 'string') code = j.code; }).catch(() => {});
  }
  const names = (ids || []).map((id) => t(`${id}.name`));
  const box = el('div', 'tip-jar');
  box.appendChild(el('div', 'tip-jar-icon', '☕'));
  const text = el('div');
  const key = !ids ? 'office.tip_text_team' : ids.length > 1 ? 'office.tip_text_many' : 'office.tip_text';
  text.appendChild(el('p', '', thanked ? t('office.supporter_thanks', { name: sup.name }) : t(key, { names: names.join(', ') })));
  text.appendChild(el('p', '', t('office.tip_credit')));     // a share goes to helmi1987, who wrote Jack Emby's tools
  text.appendChild(el('p', '', t('office.tip_shelter')));    // what goes beyond our work goes to animal shelters (Benj)
  // the key part only once the support page hands keys out (Benj issues none by hand) — or when one is saved
  if (supportPage(sup) || sup.state !== 'none') text.appendChild(supporterPart(sup));
  const claim = sup.claim && sup.claim.open > 0;     // the support page was opened from here: its key may be waiting
  if (claim) text.appendChild(el('p', 'tip-jar-claim', t('office.supporter_claim_text')));
  const cb = el('input');
  cb.type = 'checkbox';
  if (ids) {                         // after hiring it may stop asking; asked for, it never nags
    const never = el('label', 'check');
    never.append(cb, el('span', '', t('office.tip_never')));
    text.appendChild(never);
  }
  box.appendChild(text);
  const buttons = [{ text: t(thanked ? 'common.close' : 'office.tip_later'), act: () => { if (cb.checked) Office.store('tip.never', '1'); } }];
  const give = (url) => () => {
    if (cb.checked) Office.store('tip.never', '1');
    window.open(url, '_blank', 'noopener,noreferrer');
  };
  if (Office.safeHref(CONFIG.sponsor_url) && /^https:/.test(CONFIG.sponsor_url)) buttons.push({ text: t('office.tip_sponsor'), act: give(CONFIG.sponsor_url) });
  const page = supportPage(sup);     // the support page shows the key right after the tip; else just the PayPal link
  if (claim) buttons.push({ text: t('office.supporter_claim'), act: async () => (await Office.supporterClaim(false)) > 0 });
  if (page) {
    buttons.push({ text: t('office.tip_give_page'), kind: thanked ? undefined : '', act: () => {
      give(code ? `${page}#claim=${code}` : page)();
      if (code) {
        Office.api.post('office.supporter_code', { opened: code }).then((j) => {
          if (j && j.ok) supporterChanged(j.supporter);
        }).catch(() => {});
      }
    } });
  }
  else if (CONFIG.tip_url) buttons.push({ text: t('office.tip_give'), kind: thanked ? undefined : '', act: give(CONFIG.tip_url) });
  Office.dialog({ title: t(thanked ? 'office.supporter_thanks_title' : ids ? 'office.tip_title' : 'office.tip_title_team'), body: box, buttons });
};

// ------------------------------------------------------------------ supporter key
/**
 * The supporter key (src/supporter.php): a thank-you for a tip, it unlocks nothing — the office is free
 * and complete. With a valid one the reminders stop (the tip jar after hiring, the team lead's ask) and
 * the team lead shows a small thank-you. {id: this server's ID, state: none|valid|other|invalid,
 * name, date, key_id, level, ask: the team lead asks now}
 */
Office.supporter = () => CONFIG.supporter || { id: null, state: 'none', ask: false };
// the thank-you's level, signed in the key (Benj, 2026-10-08): only the plate's picture — not a rank, it unlocks nothing
// — a little office story: one coffee, a round, a cake, the whole team toasts a pay rise (Benj, 2026-10-09: 🥂 for the raise)
const SUPPORTER_PICTURES = { coffee: '☕', round: '☕☕', cake: '🍰', raise: '🥂' };
/** {level, icon, text} of a key — a coffee when it names none (every key from before levels) */
Office.supporterLevel = (sup) => {
  const level = sup && Object.prototype.hasOwnProperty.call(SUPPORTER_PICTURES, sup.level) ? sup.level : 'coffee';
  return { level, icon: SUPPORTER_PICTURES[level], text: t(`office.supporter_level_${level}`) };
};
/**
 * The plate's pictures: each level of the valid keys once, in level order (☕ ☕☕ 🍰 🥂) — never a count, never a
 * rank; {icon, text} (text: the levels' words, for the title)
 */
Office.supporterPictures = (sup) => {
  const have = sup && Array.isArray(sup.levels) && sup.levels.length ? sup.levels : [Office.supporterLevel(sup).level];
  const levels = Object.keys(SUPPORTER_PICTURES).filter((l) => have.includes(l));
  return { icon: levels.map((l) => SUPPORTER_PICTURES[l]).join(' '), text: levels.map((l) => t(`office.supporter_level_${l}`)).join(' · ') };
};
function supporterChanged(info) {
  if (info) CONFIG.supporter = info;
  claimArm();
  if (Office.current && Office.current.supporterChanged) Office.current.supporterChanged();
}
const keyDay = (iso) => {
  const d = new Date(`${iso}T12:00:00`);
  return isNaN(d) ? iso : intl((l) => new Intl.DateTimeFormat(l, { day: '2-digit', month: '2-digit', year: 'numeric' })).format(d);
};
Office.fmt.day = keyDay;

/** The support page (OFFICE_SUPPORT_URL) with this server's ID and the office's language, null while there is none */
function supportPage(sup) {
  if (typeof CONFIG.support_url !== 'string' || !/^https:\/\//i.test(CONFIG.support_url)) return null;
  try {
    const url = new URL(CONFIG.support_url);
    if (sup.id) url.searchParams.set('id', sup.id);
    url.searchParams.set('lang', Office.lang);
    return url.href;
  } catch (e) { return null; }
}

/** The tip jar's part about the keys: each kept key (picture, level, date, «Remove…»), this server's ID (for the support page or the tip's note) and «Enter key…» */
function supporterPart(sup) {
  const part = el('div', 'tip-jar-key');
  part.appendChild(el('div', 'field-title', t('office.supporter_title')));
  const keys = Array.isArray(sup.keys) ? sup.keys : [];
  if (keys.length) {
    const list = el('ul', 'tip-jar-keys');
    keys.forEach((k) => {
      const li = el('li', k.state === 'valid' ? '' : 'off');
      const level = Office.supporterLevel(k);
      const words = k.state === 'valid' ? t('office.supporter_key_row', { icon: level.icon, level: level.text, name: k.name, date: keyDay(k.date) })
        : k.state === 'other' ? t('office.supporter_other', { id: k.key_id, server: sup.id || '?' }) : t('office.supporter_bad_saved');
      li.appendChild(el('span', '', words));
      const rm = el('button', 'btn small danger plain', t('office.supporter_remove'));
      rm.type = 'button';
      rm.onclick = () => Office.supporterRemoveDialog(k);
      li.appendChild(rm);
      list.appendChild(li);
    });
    part.appendChild(list);
  }
  if (sup.state !== 'valid') {
    const how = !sup.id ? 'office.supporter_no_id' : supportPage(sup) ? 'office.supporter_text_page' : null;
    if (how) part.appendChild(el('p', '', t(how)));
  }
  const line = el('div', 'toolbar');
  const button = (text, cls, act) => {
    const b = el('button', cls, text);
    b.type = 'button';
    b.onclick = act;
    line.appendChild(b);
  };
  if (sup.id && sup.state !== 'valid') {
    const id = el('code', '', sup.id);
    id.title = t('office.supporter_id_title');
    line.append(el('span', '', t('office.supporter_id')), id);
    button(t('common.copy'), 'btn small plain', () => Office.copy(sup.id));
  }
  if (sup.id) button(t(keys.length ? 'office.supporter_enter_more' : 'office.supporter_enter'), 'btn small plain', Office.supporterKeyDialog);
  if (line.childNodes.length) part.appendChild(line);
  return part;
}

/** «Enter key…»: the office checks it (signature, this server) and keeps it in data/office/supporter.json */
Office.supporterKeyDialog = function supporterKeyDialog() {
  const sup = Office.supporter();
  const box = el('div');
  box.appendChild(el('p', '', t('office.supporter_enter_text')));
  const field = el('div', 'field');
  const input = el('input', 'input mono');
  input.autocomplete = 'off';
  input.spellcheck = false;
  input.maxLength = 4000;
  input.setAttribute('aria-label', t('office.supporter_title'));
  field.appendChild(input);
  if (sup.id) field.appendChild(el('small', '', t('office.supporter_for', { id: sup.id })));
  box.appendChild(field);
  const msg = el('p', 'callout warn');
  msg.hidden = true;
  box.appendChild(msg);
  Office.dialog({
    title: t('office.supporter_title'),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.supporter_save'), kind: '', act: async () => {
        const key = input.value.trim();
        if (!key) { msg.textContent = t('office.supporter_empty'); msg.hidden = false; input.focus(); return false; }
        const j = await Office.api.post('office.supporter_set', { key });
        if (!j.ok) { msg.textContent = Office.errorText(j.error); msg.hidden = false; return false; }
        supporterChanged(j.supporter);
        Office.toast(t(j.added === false ? 'office.supporter_known' : 'office.supporter_saved', { name: (j.key && j.key.name) || j.supporter.name || '' }));
        return true;
      } },
    ],
  });
};

/** «Remove…» of one kept key (k: its row in CONFIG.supporter.keys): nothing changes but the thank-you (and the reminders may come back) */
Office.supporterRemoveDialog = function supporterRemoveDialog(k) {
  const level = Office.supporterLevel(k);
  const which = k && k.state === 'valid' ? t('office.supporter_key_row', { icon: level.icon, level: level.text, name: k.name, date: keyDay(k.date) }) : '';
  const box = el('div');
  if (which) box.appendChild(el('p', '', which));
  box.appendChild(el('p', '', t('office.supporter_remove_text')));
  Office.dialog({
    title: t('office.supporter_remove_title'),
    body: box,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.supporter_remove_do'), kind: 'danger', act: async () => {
        const j = await Office.api.post('office.supporter_remove', k && k.ref ? { ref: k.ref } : {});
        if (!j.ok) { Office.toast(Office.errorText(j.error), true); return false; }
        supporterChanged(j.supporter);
        Office.toast(t('office.supporter_removed'));
        return true;
      } },
    ],
  });
};

/**
 * Ask the support page (through the agent — never from here) for the key a tip made: auto = the one ask on its own,
 * a minute after the page was opened; else the tip jar's «I've tipped — look for my key». Returns the keys found.
 */
Office.supporterClaim = async function supporterClaim(auto) {
  const j = await Office.api.post('office.supporter_claim', auto ? { auto: true } : {});
  if (!j.ok) {
    if (!auto) Office.toast(Office.errorText(j.error), true);
    return 0;
  }
  supporterChanged(j.supporter);
  if (j.found > 0) Office.toast(t('office.supporter_arrived', { name: j.supporter.name || '' }));
  else if (!auto) Office.toast(t(j.failed > 0 ? 'office.supporter_claim_offline' : 'office.supporter_claim_none'), j.failed > 0);
  return j.found || 0;
};
// the one ask on its own: due `wait` seconds after the answer that said so, made on the next state refresh after that
let claimDue = null;
function claimArm() {
  const c = Office.supporter().claim;
  claimDue = c && typeof c.wait === 'number' ? Date.now() + c.wait * 1000 : null;
}
function claimLook() {
  if (claimDue === null || Date.now() < claimDue || document.hidden) return;
  claimDue = null;                  // once — the agent marks the code asked, a second page asks nothing
  Office.supporterClaim(true);
}

/** The team lead's ask answered — 'later' (again in a month, at most twice more) or 'never'; kept on the server */
Office.supporterAsk = async function supporterAsk(answer) {
  const j = await Office.api.post('office.supporter_ask', { answer });
  if (!j.ok) { Office.toast(Office.errorText(j.error), true); return false; }
  supporterChanged(j.supporter);
  return true;
};

// ------------------------------------------------------------------ «Report a problem or a wish…»
/*
 * The office's reports to its makers (agent/lib/report.php, briefs/uso-feedback-concept.md). The page only asks the
 * agent: office.reports (the list, the cap), office.report_preview (everything that would be sent, part by part, a
 * token — no network) and, on «Send» only, office.report_send (the previewed parts still ticked). «Send» stays off
 * until the preview was looked at; a word changed after it: the preview goes, «Send» with it. The draft is kept per
 * browser (Office.store report.draft) — a click outside or Escape loses nothing. The office shows only its own words
 * (errors.report_*), never a text of the makers' inbox.
 */
const REPORT_KINDS = ['bug', 'wish', 'question'];
const REPORT_TITLE_MAX = 100;
const REPORT_TEXT_MAX = 4096;      // bytes, UTF-8
const REPORT_TEXT_MIN = 10;
const REPORT_NAME_MAX = 40;
const REPORT_PARTS = ['versions', 'unraid', 'language', 'team', 'error', 'log'];
const utf8Bytes = (s) => (typeof TextEncoder === 'function' ? new TextEncoder().encode(s).length : unescape(encodeURIComponent(s)).length);

/** The page's last error of a desk as the agent takes it: a key, at most 8 plain params, a time */
function reportLastError(desk) {
  const e = Office.lastError[desk];
  if (!e || !/^[a-z0-9_.]{1,64}$/.test(e.key)) return undefined;
  const params = {};
  Object.entries(e.params || {}).filter(([k, v]) => /^[A-Za-z_][A-Za-z0-9_]{0,31}$/.test(k) && ['string', 'number', 'boolean'].includes(typeof v))
    .slice(0, 8).forEach(([k, v]) => { params[k] = typeof v === 'string' ? v.slice(0, 1000) : v; });
  return { key: e.key, params, at: e.at };
}

/** An error of the report's in the office's words — report_day with the time the next may go */
function reportError(error) {
  const p = (error && error.params) || {};
  if (error && error.key === 'report_day') {
    return t('errors.report_day', { n: Number(p.n) || 25, when: typeof p.next === 'number' ? Office.fmt.date(p.next) : '?' });
  }
  return Office.errorText(error);
}

Office.reportDialog = function reportDialog(deskId) {
  const draft = Office.storeJson('report.draft') || {};
  const desks = [['office', t('office.report_desk_office')]].concat(Office.staffInOrder().map((d) => [d.id, t(`${d.id}.name`)]));
  const want = deskId || draft.desk || 'office';
  const state = { kind: REPORT_KINDS.includes(draft.kind) ? draft.kind : 'bug', preview: null, sent: false, cap: null, closed: false };
  const box = el('div', 'sso-report');

  // the head: the team lead's line, the other ways, the cap
  box.appendChild(el('p', '', t('office.report_intro')));
  const ways = el('p', 'sso-report-ways');
  const link = (href, text) => {
    const a = el('a', '', text);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    return a;
  };
  if (Office.safeHref(CONFIG.forum_url) && /^https:/.test(CONFIG.forum_url)) ways.append(link(CONFIG.forum_url, t('office.report_forum')), ' · ');
  if (Office.safeHref(CONFIG.issues_url) && /^https:/.test(CONFIG.issues_url)) ways.appendChild(link(CONFIG.issues_url, t('office.report_issues')));
  if (ways.children.length) box.appendChild(ways);
  const capLine = el('p', 'sso-report-cap');
  capLine.hidden = true;
  box.appendChild(capLine);

  // the form
  const form = el('div', 'sso-report-form');
  const field = (label, input, hint) => {
    const f = el('div', 'field');
    const l = el('label', '', label);
    f.append(l, input);
    if (hint) f.appendChild(hint);
    form.appendChild(f);
    return f;
  };
  const kinds = el('div', 'seg sso-report-kinds');
  kinds.setAttribute('role', 'group');
  kinds.setAttribute('aria-label', t('office.report_kind'));
  const kindButtons = REPORT_KINDS.map((k) => {
    const b = el('button', '', t(`office.report_kind_${k}`));
    b.type = 'button';
    b.dataset.kind = k;
    b.setAttribute('aria-pressed', String(k === state.kind));
    b.onclick = () => {
      state.kind = k;
      kindButtons.forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.kind === k)));
      changed();
    };
    kinds.appendChild(b);
    return b;
  });
  field(t('office.report_kind'), kinds);
  const desk = el('select', 'input');
  desks.forEach(([id, name]) => {
    const o = el('option', '', name);
    o.value = id;
    desk.appendChild(o);
  });
  desk.value = desks.some(([id]) => id === want) ? want : 'office';
  field(t('office.report_desk'), desk);
  const title = el('input', 'input');
  title.maxLength = REPORT_TITLE_MAX;
  title.autocomplete = 'off';
  title.value = typeof draft.title === 'string' ? draft.title : '';
  field(t('office.report_subject'), title);
  const text = el('textarea', 'input sso-report-text');
  text.rows = 6;
  text.value = typeof draft.text === 'string' ? draft.text : '';
  const count = el('small', 'sso-report-count');
  field(t('office.report_text'), text, count);
  // no name field (Benj, 2026-10-08): nobody is answered by name — the forum is the place for a conversation (the agent
  // still takes an empty one: report.php reportWords())
  box.appendChild(form);

  const msg = el('p', 'callout warn');
  msg.hidden = true;
  const show = el('button', 'btn small', t('office.report_preview'));
  show.type = 'button';
  const showLine = el('div', 'toolbar sso-report-show');
  showLine.appendChild(show);
  box.append(msg, showLine);
  const preview = el('div', 'sso-report-preview');
  preview.hidden = true;
  box.appendChild(preview);
  const done = el('div', 'sso-report-done');
  done.hidden = true;
  box.appendChild(done);
  const yours = el('details', 'sso-report-yours');
  yours.hidden = true;
  box.appendChild(yours);

  const words = () => ({ kind: state.kind, desk: desk.value, title: title.value.trim(), text: text.value.trim() });
  const keep = () => { if (!state.sent) Office.storeJson('report.draft', words()); };
  const say = (s) => { msg.textContent = s || ''; msg.hidden = !s; };
  let send = null;
  const sendable = () => !!state.preview && !state.closed && !(state.cap && state.cap.left <= 0) && Office.agent.running;
  const counter = () => {
    const used = utf8Bytes(text.value.trim());
    count.textContent = t('office.report_count', { used, max: REPORT_TEXT_MAX });
    count.className = 'sso-report-count' + (used > REPORT_TEXT_MAX ? ' missing' : '');
  };
  function changed() {
    keep();
    counter();
    if (state.preview) {
      state.preview = null;          // what was shown isn't what would go any more
      preview.hidden = true;
      preview.innerHTML = '';
    }
    if (send) send.disabled = !sendable();
  }
  [title, text].forEach((f) => { f.oninput = changed; });
  desk.onchange = changed;
  counter();

  function paintCap() {
    const c = state.cap;
    if (state.closed) {
      capLine.textContent = t('office.report_closed_note');
    } else if (c && c.left <= 0) {
      capLine.textContent = t('office.report_none_left', { when: c.next ? Office.fmt.date(c.next) : '?' });
    } else if (c) {
      capLine.textContent = t('office.report_left', { n: c.left, cap: c.cap });
    }
    capLine.className = 'sso-report-cap' + (state.closed || (c && c.left <= 0) ? ' callout warn' : '');
    capLine.hidden = !c && !state.closed;
  }
  function paintYours(list) {
    yours.innerHTML = '';
    yours.hidden = !list.length;
    if (!list.length) return;
    yours.appendChild(el('summary', '', t('office.report_yours', { n: list.length })));
    const ul = el('ul', 'shortlist');
    list.forEach((r) => {
      const li = el('li');
      const head = el('span', 'sso-report-yours-title', `#${r.number} ${r.title}`);
      li.appendChild(head);
      li.appendChild(el('span', '', t('office.report_yours_row', { kind: t(`office.report_kind_${r.kind}`),
        desk: r.desk === 'office' ? t('office.report_desk_office') : t(`${r.desk}.name`), day: Office.fmt.date(r.sent) })));
      ul.appendChild(li);
    });
    yours.appendChild(ul);
  }
  function takeCap(j) {
    if (typeof j.left === 'number') state.cap = { n: j.n, left: j.left, cap: j.cap || 25, next: j.next || null };
    if (typeof j.closed === 'boolean') state.closed = j.closed;
    paintCap();
  }

  /** One part of the preview: a tick box (or none: always sent), its name, its content folded under it */
  function partRow(id, content, ticked, fixed) {
    const row = el('div', 'sso-report-part');
    const head = el('label', 'check');
    const cb = el('input');
    cb.type = 'checkbox';
    cb.checked = ticked;
    cb.disabled = !!fixed;
    cb.dataset.part = id;
    head.append(cb, el('span', '', t(`office.report_part.${id}`)));
    row.appendChild(head);
    if (content) row.appendChild(content);
    preview.appendChild(row);
    return cb;
  }
  function paintPreview(j) {
    preview.innerHTML = '';
    preview.hidden = false;
    preview.appendChild(el('div', 'field-title sso-group-title', t('office.report_preview_title')));
    const p = j.parts || {};
    const w = words();
    const mine = el('div', 'sso-report-words');
    mine.appendChild(el('div', 'sso-report-words-head', `${t(`office.report_kind_${w.kind}`)} · ${w.desk === 'office' ? t('office.report_desk_office') : t(`${w.desk}.name`)}`));
    mine.appendChild(el('strong', '', w.title));
    mine.appendChild(el('pre', 'code sso-report-text-shown', w.text));
    partRow('words', mine, true, true);
    (j.hints || []).forEach((h) => preview.appendChild(el('p', 'callout', t(`office.report_hint.${h}`))));
    const line = (s) => el('div', 'sso-report-value', s);
    const v = p.versions || {};
    const boxes = [];
    const ticked = new Set(j.ticked || []);
    boxes.push(partRow('versions', line(v.engine ? t('office.report_versions_engine', { office: v.office, engine: v.engine }) : t('office.report_versions', { office: v.office })), ticked.has('versions')));
    boxes.push(partRow('unraid', line(p.unraid || '?'), ticked.has('unraid')));
    const l = p.language || {};
    boxes.push(partRow('language', line(t('office.report_language', { lang: l.lang || '?', browser: l.browser || '?' })), ticked.has('language')));
    boxes.push(partRow('team', line((p.team || []).map((id) => t(`${id}.name`)).join(', ')), ticked.has('team')));
    if (p.error) {
      const params = Object.entries(p.error.params || {}).map(([k, x]) => `${k}: ${x}`).join(' · ');
      const what = line(p.error.at ? t('office.report_error_at', { key: p.error.key, time: Office.fmt.date(p.error.at) }) : p.error.key);
      if (params) what.appendChild(el('small', '', params));
      boxes.push(partRow('error', what, ticked.has('error')));
    }
    const log = typeof p.log === 'string' ? p.log : '';
    const logBox = el('div');
    if (log) {
      const n = log.split('\n').length;
      logBox.appendChild(el('small', '', t('office.report_log_lines', { n })));
      logBox.appendChild(el('pre', 'code sso-report-log', log));          // in full, it scrolls itself
      const hidden = Object.entries(j.hidden || {});
      if (hidden.length) logBox.appendChild(el('small', 'sso-report-hidden', t('office.report_hidden', { list: hidden.map(([as, was]) => `${as} = ${was}`).join(', ') })));
    } else {
      logBox.appendChild(el('small', '', t('office.report_log_none')));
    }
    const logCb = partRow('log', logBox, !!log && ticked.has('log'));
    if (!log) logCb.disabled = true;
    boxes.push(logCb);
    const id = line(t('office.report_id_value', { id: j.id || '?' }));
    id.appendChild(el('small', '', t('office.report_id_why')));
    partRow('id', id, true, true);
    preview.appendChild(el('p', 'sso-report-where', t('office.report_where')));
    state.boxes = boxes;
  }

  show.onclick = async () => {
    const w = words();
    if (!w.title || w.text.length < REPORT_TEXT_MIN) { say(t('errors.report_incomplete', { min: REPORT_TEXT_MIN })); return; }
    if (utf8Bytes(w.text) > REPORT_TEXT_MAX) { say(t('office.report_too_long', { max: REPORT_TEXT_MAX })); return; }
    say('');
    show.disabled = true;
    try {
      const browser = String((navigator.languages && navigator.languages[0]) || navigator.language || '').slice(0, 2).toLowerCase();
      const j = await Office.api.post('office.report_preview', { ...w, lang: Office.lang, browser: /^[a-z]{2}$/.test(browser) ? browser : undefined,
        error: reportLastError(w.desk) });
      if (!j.ok) { say(reportError(j.error)); return; }
      state.preview = j;
      takeCap(j);
      paintPreview(j);
    } finally {
      show.disabled = !Office.agent.running;
      if (send) send.disabled = !sendable();
    }
  };

  async function doSend() {
    if (!state.preview) return false;
    const parts = (state.boxes || []).filter((b) => b.checked && !b.disabled && REPORT_PARTS.includes(b.dataset.part)).map((b) => b.dataset.part);
    const j = await Office.api.post('office.report_send', { ...words(), token: state.preview.token, parts });
    if (!j.ok) {
      say(reportError(j.error));
      if (j.error && j.error.key === 'report_stale') changed();
      if (j.error && j.error.key === 'report_closed') state.closed = true;
      if (j.error && j.error.key === 'report_day') {
        const n = Number(j.error.params && j.error.params.n) || 25;
        state.cap = { n, left: 0, cap: n, next: j.error.params && j.error.params.next };
      }
      paintCap();
      setTimeout(() => { send.disabled = !sendable(); }, 0);     // after the dialog gave the buttons back
      return false;
    }
    state.sent = true;
    Office.store('report.draft', null);
    say('');
    [form, showLine, preview, capLine].forEach((n) => { n.hidden = true; });
    done.hidden = false;
    done.innerHTML = '';
    done.appendChild(el('p', 'sso-report-sent', t('office.report_sent', { number: String(j.number) })));
    if (Office.safeHref(j.url) && /^https:\/\/github\.com\//.test(j.url)) {
      const p = el('p');
      p.appendChild(link(j.url, t('office.report_sent_link')));
      done.appendChild(p);
    }
    done.appendChild(el('p', 'role', t('office.report_sent_note', { n: typeof j.left === 'number' ? j.left : 0 })));
    send.hidden = true;
    dlg.buttons[0].textContent = t('common.close');
    loadYours();
    return false;
  }

  async function loadYours() {
    if (!Office.agent.running) return;
    const j = await Office.api.post('office.reports', {});
    if (!j.ok) return;
    if (!state.sent) takeCap(j);
    paintYours(j.reports || []);
  }

  const dlg = Office.dialog({
    title: t('office.report_title'),
    body: box,
    wide: true,
    buttons: [
      { text: t('common.cancel') },
      { text: t('office.report_send'), kind: '', act: doSend },
    ],
    onClose: () => {
      keep();
      $('#sso-dialog').classList.remove('sso-report-dialog');
      $('#sso-dialog-backdrop').classList.remove('sso-report-backdrop');
    },
  });
  $('#sso-dialog').classList.add('sso-report-dialog');           // a phone: the dialog takes the screen (office.css)
  $('#sso-dialog-backdrop').classList.add('sso-report-backdrop');
  send = dlg.buttons[1];
  send.disabled = true;
  show.disabled = !Office.agent.running;
  if (!Office.agent.running) say(t('errors.agent_away'));
  title.focus();
  loadYours();
  return dlg;
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
    // «As of …» while the page shows a state older than the desk looks after (paintAsOf())
    const asof = el('div', 'role');
    asofNodes.set(desk.id, asof);
    paintAsOf(desk.id, asof);
    text.appendChild(asof);
  }
  head.appendChild(text);
  const act = el('div', 'deskhead-actions');
  (actions || []).forEach((a) => act.appendChild(a));
  head.appendChild(act);
  return { head, bubble: b, actions: act };
};

// ------------------------------------------------------------------ reception
async function reception(root) {
  Office.selbar(null);                      // a reception drawn anew is never still arranging
  const head = el('div', 'deskhead');
  head.appendChild(Office.avatar(''));
  const text = el('div', 'deskhead-text');
  text.append(el('h1', '', t('office.welcome', { host: CONFIG.host })), el('div', 'role', t('office.reception_role')));
  head.appendChild(text);
  // theme-switch: Automatic · Dark · Light on the right (theme-switch.js, only while OFFICE_THEME_SWITCH is on)
  if (Office.theme) { const acts = el('div', 'deskhead-actions'); acts.appendChild(Office.theme.control()); head.appendChild(acts); }
  // size-switch: A · A · A beside it (size-switch.js, only while OFFICE_SIZE_SWITCH is on)
  if (Office.size) (head.querySelector('.deskhead-actions') || head.appendChild(el('div', 'deskhead-actions'))).appendChild(Office.size.control());
  root.appendChild(head);
  if (noData()) return;                     // nobody's state without the data folder: the notice above says why

  const grid = el('div', 'reception');
  root.appendChild(grid);
  const cards = new Map();
  for (const desk of Office.staffInOrder()) {
    const card = el('div', 'desk-card');
    card.dataset.desk = desk.id;
    cards.set(desk.id, card);
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
        // the cards show what the desks kept (never a look just for the reception): an old one says when it was
        const asof = receptionAsOf(desk.id);
        if (asof) facts.appendChild(asof);
      }).catch(() => { bubble.textContent = t('office.no_news'); });
    } else {
      bubble.textContent = t('office.no_news');
    }
  }
  arrangeable(head, grid, cards);
}

/**
 * «Change the order» at the reception: the user puts the staff in the order they like — the reception's cards,
 * the tabs and the team lead's «The team» follow it (Office.deskRank), kept on the server for every browser
 * (office.staff_order, src/staff.php). While arranging, the cards are small (name and role) and carry big ▲ / ▼
 * buttons (the words as a tip and for screen readers); the team lead stays first (he leads the team, his card
 * says so). The head's button turns into «✓ Done» — every move is saved at once (the last one wins, never two
 * at a time), so there is nothing to apply —, «As at the start» beside it, a hint under the welcome. The
 * selection bar at the bottom (the same two buttons) shows only while the head's are scrolled out of view
 * (a phone with many desks), never a second «✓ Done» on screen. The moved card stays where it is on screen
 * (Office.keepInPlace), the keyboard on the arrow it pressed.
 */
function arrangeable(head, grid, cards) {
  const all = () => [...cards.keys()];
  const movable = () => all().filter((id) => !Office.desks.get(id).always);
  if (movable().length < 2) return;           // the team lead and one more: nothing to put in order
  const lead = all().find((id) => Office.desks.get(id).always) || 'caretaker';
  // the head: «Change the order», while arranging «✓ Done» (the same button) with «As at the start» before it
  const start = el('button', 'btn small plain', t('office.order_change'));
  start.type = 'button';
  start.title = t('office.order_change_title');
  const back = el('button', 'btn small plain', t('office.order_default'));
  back.type = 'button';
  back.title = t('office.order_default_title');
  back.hidden = true;
  const acts = $('.deskhead-actions', head) || el('div', 'deskhead-actions');   // beside the theme switch, if any
  const group = el('span', 'order-buttons');                                    // the two wrap as one on a phone
  group.append(back, start);
  acts.appendChild(group);
  head.appendChild(acts);
  const hint = el('div', 'order-hint', t('office.order_hint', { name: t(`${lead}.name`) }));
  hint.hidden = true;
  ($('.deskhead-text', head) || head).appendChild(hint);

  // every card gets its line for arranging (shown only then): ▲ / ▼, the team lead a word why he stays first
  const moves = new Map();
  for (const [id, card] of cards) {
    const line = el('div', 'desk-card-move');
    if (Office.desks.get(id).always) {
      const chip = el('span', 'chip quiet', t('office.order_lead'));
      chip.title = t('office.order_lead_title', { name: t(`${id}.name`) });
      line.appendChild(chip);
    } else {
      // a big arrow (44 px, for fingers too): the word as a bubble on hover (initTips — the click is the move)
      // and as its name for screen readers
      const button = (glyph, label, step) => {
        const b = el('button', 'btn plain order-arrow', glyph);
        b.type = 'button';
        b.dataset.tip = label;
        b.setAttribute('aria-label', `${t(`${id}.name`)}: ${label}`);
        b.onclick = () => move(id, step, b);
        line.appendChild(b);
        return b;
      };
      moves.set(id, { up: button('▲', t('office.order_up'), -1), down: button('▼', t('office.order_down'), 1) });
    }
    card.appendChild(line);
  }

  const arranging = () => grid.classList.contains('arranging');
  const byRank = (a, b) => Office.deskRank(a) - Office.deskRank(b);
  /** they stand as a fresh office has them (desk.json's order): «As at the start» has nothing to do */
  const isDefault = () => {
    const now = movable().sort(byRank);
    return now.join() === CONFIG.desks.map((d) => d.id).filter((id) => now.includes(id)).join();
  };
  /** the cards in the staff's order, ▲ / ▼ only where there is somewhere to go */
  const place = () => {
    const ids = all().sort(byRank);
    ids.forEach((id, i) => { if (grid.children[i] !== cards.get(id)) grid.insertBefore(cards.get(id), grid.children[i] || null); });
    const free = ids.filter((id) => moves.has(id));
    free.forEach((id, i) => { moves.get(id).up.disabled = i === 0; moves.get(id).down.disabled = i === free.length - 1; });
  };
  /** what stays where it is when the cards change size: the card just moved while all of it is in view (above the
      bar), else the first card that begins in view (or the one reaching into it from above) */
  const seen = (c) => { const r = c.getBoundingClientRect(); return r.bottom > 0 && r.top < window.innerHeight; };
  const inView = () => {
    const bar = $('.selbar', ROOT);
    const bottom = bar ? bar.getBoundingClientRect().top : window.innerHeight;
    const whole = moved && moved.getBoundingClientRect();
    if (whole && whole.top >= 0 && whole.bottom <= bottom) return moved;
    return [...grid.children].find((c) => { const top = c.getBoundingClientRect().top; return top >= 0 && top < bottom; })
      || [...grid.children].find(seen) || grid;
  };
  let moved = null;
  const mark = (card) => { if (moved) moved.classList.remove('moved'); moved = card; if (card) card.classList.add('moved'); };
  /** the head's buttons as the mode is: «Change the order» — or «✓ Done» (filled) and «As at the start» */
  const headButtons = () => {
    const on = arranging();
    start.textContent = on ? t('office.order_done') : t('office.order_change');
    start.title = on ? t('office.order_done_title') : t('office.order_change_title');
    start.classList.toggle('plain', !on);
    back.hidden = hint.hidden = !on;
    back.disabled = isDefault();
  };
  /** the bar at the bottom: only while arranging and the head's buttons are out of view (headSeen, watched below) */
  let headSeen = true, watch = null;
  const bar = () => {
    if (!grid.isConnected) { stopWatch(); return; }       // the reception went (route() took the bar with it)
    if (!arranging() || headSeen) { Office.selbar(null); return; }
    Office.selbar({
      title: t('office.order_title'),
      sub: t('office.order_hint', { name: t(`${lead}.name`) }),
      buttons: [
        { text: t('office.order_default'), kind: 'plain', disabled: isDefault(), act: reset },
        { text: t('office.order_done'), act: done },
      ],
    });
  };
  /** watches whether «✓ Done» in the head is in view — under what Unraid keeps fixed (its sticky menu at the top, the
      footer below, --under) it counts as out of view; without IntersectionObserver the bar stays, as before */
  const startWatch = () => {
    stopWatch();
    if (!window.IntersectionObserver) { headSeen = false; return; }
    const menu = document.getElementById('menu');
    const above = menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.offsetHeight : 0;
    const under = parseFloat(getComputedStyle(ROOT).getPropertyValue('--under')) || 0;
    watch = new IntersectionObserver((entries) => {
      headSeen = entries[entries.length - 1].intersectionRatio >= 0.9;
      bar();
    }, { rootMargin: `-${Math.round(above)}px 0px -${Math.round(under)}px 0px`, threshold: [0, 0.9] });
    watch.observe(start);
  };
  const stopWatch = () => { if (watch) { watch.disconnect(); watch = null; } headSeen = true; };

  function open() {
    Office.hideTip();
    Office.keepInPlace(inView(), () => { grid.classList.add('arranging'); headButtons(); place(); });
    startWatch();
    bar();
  }
  function done() {
    Office.hideTip();
    stopWatch();
    Office.keepInPlace(inView(), () => { grid.classList.remove('arranging'); headButtons(); mark(null); });
    bar();
    start.focus({ preventScroll: true });
  }
  /** one step forward (-1) or back (+1) among the desks that can move */
  function move(id, step, b) {
    const ids = movable().sort(byRank);
    const i = ids.indexOf(id);
    const j = i + step;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    const card = cards.get(id);
    const focused = document.activeElement === b;
    Office.hideTip();
    setStaffOrder(ids);
    Office.keepInPlace(card, place);
    mark(card);
    // the keyboard keeps its place: on the same arrow, or at the end of the line on the other one
    if (focused) (b.disabled ? moves.get(id)[step < 0 ? 'down' : 'up'] : b).focus({ preventScroll: true });
    headButtons();
    bar();
    saveStaffOrder(ids);
  }
  /** «As at the start»: desk.json's order again (staff.json without "order") */
  function reset() {
    setStaffOrder([]);
    Office.keepInPlace(inView(), place);
    mark(null);
    headButtons();
    bar();
    saveStaffOrder([]);
  }
  start.onclick = () => (arranging() ? done() : open());
  back.onclick = reset;
}

/** The staff's order in this page at once (the movable ids as the user put them; [] = desk.json's) — the tabs follow */
function setStaffOrder(ids) {
  const always = CONFIG.desks.filter((d) => d.always).map((d) => d.id);
  staffOrder = [...always, ...ids, ...CONFIG.desks.map((d) => d.id).filter((id) => !always.includes(id) && !ids.includes(id))];
  tabs();
}

/** Keeps the order on the server (office.staff_order): one request at a time, only the newest order goes after it */
let orderSending = false, orderNext = null, orderKept = staffOrder.slice();
async function saveStaffOrder(ids) {
  orderNext = ids;
  if (orderSending) return;
  orderSending = true;
  try {
    while (orderNext) {
      const send = orderNext;
      orderNext = null;
      const j = await Office.api.post('office.staff_order', { order: send });
      if (!j.ok || !Array.isArray(j.order)) {
        // not kept: back to the order the server has, the reception drawn anew (no longer arranging)
        orderNext = null;
        staffOrder = orderKept.slice();
        Office.toast(Office.errorText(j.error), true);
        if (!Office.current) route(); else tabs();
        return;
      }
      orderKept = j.order;
      if (!orderNext) { staffOrder = j.order.slice(); tabs(); }
    }
  } finally {
    orderSending = false;
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

/** Which language: like this browser, or one chosen for it */
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
  const like = CONFIG.languages.find((l) => l.code === browserLanguage(CONFIG.languages.map((x) => x.code)));
  option('', t('office.language_browser', { name: like ? like.name : 'English' }));
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
        rememberLanguage();
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
  const j = await Office.api.get({ a: 'log' });
  if (!j.ok && j.error) {
    pre.textContent = t('office.log_failed');
    return;
  }
  pre.textContent = (j.lines || []).join('\n') || t('office.log_empty');
  pre.scrollTop = pre.scrollHeight;
}

Office.help = function help() {
  const box = el('div');
  const dl = el('dl');
  // each title is a place the search finds (the office's places, officePlaces()): its key is its anchor
  const item = (key, ...parts) => {
    dl.appendChild(Office.place(key, el('dt', '', t(key))));
    const dd = el('dd');
    parts.forEach((p) => dd.append(p));
    dl.appendChild(dd);
  };
  const code = (s) => el('code', '', s);
  item('help.office_title', t('help.office_text'));
  item('help.search_title', t('help.search_text', { keys: shortcutText() }));
  item('help.order_title', t('help.order_text'));
  item('help.agent_title', t('help.agent_text'));
  item('help.dot_title', t('help.dot_text'));
  item('help.start_title', t('help.start_text'));
  item('help.languages_title', t('help.languages_text'), ' ', code('public/lang/<code>.json'), ', ',
    code('public/desks/<desk>/lang/<code>.json'), '.');
  if (Office.theme) item('help.theme_title', t('help.theme_text'));      // theme-switch
  if (Office.size) item('help.size_title', t('help.size_text'));      // size-switch
  item('help.security_title', t('help.security_text'));
  item('help.report_title', t('help.report_text'));
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
  // «Report a problem or a wish…» — about the desk shown (the reception: the office as a whole)
  if (CONFIG.report) items.push({ text: t('office.report_menu'), act: () => Office.reportDialog(Office.current ? Office.current.id : 'office') });
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
  a.href = 'https://github.com/dropnook/UnraidSecretaryOffice';
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  f.append(a);
}

// ------------------------------------------------------------------ places and the search
/*
 * The search (Benj, 2026-10-08): the magnifier in the top line, or ⌘K / Ctrl+K while the office has focus, opens a
 * palette under it that finds PLACES — a desk, a section, a tile, a step or a setting of a setup, a term of «How to read
 * this page», a guide — in every language the office speaks at once («Partn» finds «Partner-Sekretariate» in an English
 * office), and jumps there: the desk's page (its route), what folds open above it, the place scrolled into view and
 * marked. What a desk's state holds (findings, entries, apps …) are its «items» (below); nothing outside #sso.
 *
 * Every desk lists its places beside Office.desk() — Office.places(ID, [{kind, key, route, anchor, text, crumb}, …]):
 *   kind    desk | section | tile | step | setting | help | guide
 *   key     the desk's lang key of the words it is found by and shown as (like T('…'))
 *   route   where it is: '#/<desk>' (the default), or a sub-route the desk's mount() opens (a tile, its setup)
 *   anchor  what the desk marks on the element: Office.place(anchor, node), or {place: anchor} among
 *           Office.sectionHead()'s extras; default the key; null = the page itself. A help term needs no mark: pageHelp()
 *           finds it by its words (its anchor 'help:<key>')
 *   text    a lang key shown under it (a term's explanation); crumb: a lang key between the desk and it («Set up»)
 *   paras   a guide's paragraphs: the prefix of its lang keys <paras>.1, <paras>.2 … (the Consultant's guides), each marked
 *           on the page as Office.place('<paras>.<n>', node) — the search lands on the sentence it met
 *   shown   () => boolean, asked when the search looks (cheap, the desk's own rule): false = the page doesn't draw it now
 *           (Ms. Dustdevil's rooms without anything in them …) — not listed at all. Without it: always drawn
 *   part    the anchor of the part of the page it lies in: Office.reveal() lands there when the place itself isn't drawn
 * The text and the paragraphs are its SECONDARY words (phase 3): a query that meets none of its own words but meets
 * them finds it below every place met by its name — the sentence shown with the words met marked.
 * The desk's places.json (beside desk.json) lists the same keys ("keys") and the texts and paragraphs ("texts",
 * a guide's as '<paras>.*'): api.php?a=places sends the keys' words in every language, asked once on the first open —
 * never with the first paint, never per keystroke; until it answers, the office's own language finds them. The texts
 * are bigger (≈ 166 KB gzip in five languages, the words ≈ 14): the office's language has them already (the strings),
 * English comes right after the words (api.php?a=places&part=text, ≈ 31 KB gzip) when the office speaks another one.
 * tests/run.php testSearchPlaces / testSearchGuides keep both lists, the anchors and the sections in step.
 */
const PLACE_KINDS = ['desk', 'section', 'tile', 'step', 'setting', 'help', 'guide'];
const PLACE_WEIGHT = { desk: 1, section: 0.9, tile: 0.85, step: 0.8, setting: 0.8, help: 0.7, guide: 0.7, item: 0.75, text: 0.6 };
const PLACES_SHOWN = 12;
const placeLists = new Map();       // desk ('' = the office itself) -> its places
let searchIndex = null;             // the places with their words, built on the first open (again for other strings)
let indexStrings = null;
let searchWords = null;             // api.php?a=places: {<key>: {<lang>: words}} — every language, once
let wordsAsked = null;
let searchTexts = null;             // api.php?a=places&part=text: {<key>: English text} — the texts and paragraphs
let textsAsked = null;
const textHays = new Map();         // '<lang>|<key>' -> ' word word … ' of a text, folded (built when first compared)

Office.places = function places(desk, list) {
  placeLists.set(desk, (Array.isArray(list) ? list : []).filter((p) => p && PLACE_KINDS.includes(p.kind) && typeof p.key === 'string' && p.key)
    .map((p) => ({
      desk, kind: p.kind, key: p.key,
      route: typeof p.route === 'string' ? p.route : desk ? `#/${desk}` : '#/',
      anchor: p.anchor !== undefined ? p.anchor : p.kind === 'help' ? `help:${p.key}` : p.key,
      text: p.text || null, crumb: p.crumb || null, act: typeof p.act === 'function' ? p.act : null,
      paras: typeof p.paras === 'string' && p.paras ? p.paras : null,
      shown: typeof p.shown === 'function' ? p.shown : null,
      part: typeof p.part === 'string' && p.part ? p.part : null,
    })));
  searchIndex = null;
};
/** A desk's places as listed (for the tests) */
Office.placesOf = (desk) => (placeLists.get(desk) || []).map((p) => ({ ...p }));
/** Mark a node as the place <anchor> (the search jumps there); the node back */
Office.place = (anchor, node) => {
  if (node && node.dataset && anchor) node.dataset.place = anchor;
  return node;
};

/** Words as the search compares them: lower case, no accents (ß → ss …), placeholders and punctuation out */
const foldText = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
  .replace(/ß/g, 'ss').replace(/æ/g, 'ae').replace(/œ/g, 'oe').replace(/ø/g, 'o').replace(/ł/g, 'l')
  .replace(/\{\w+\}/g, ' ').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
/** A text as a result shows it: placeholders it can't fill out, no trailing … or : */
const placeLabel = (s) => String(s ?? '').replace(/\{\w+\}/g, '').replace(/\s+/g, ' ').replace(/^[\s·:,–—-]+|[\s·:,–—…-]+$/g, '').trim();
/** The words of a lang value: every form of a plural, Unraid's ⟦labels⟧ in English and in Unraid's words */
const placeWordsOf = (v) => {
  const s = v && typeof v === 'object' ? Object.values(v).filter((x) => typeof x === 'string').join(' ') : String(v ?? '');
  return s.indexOf('⟦') < 0 ? s : `${s.replace(/[⟦⟧]/g, '')} ${unraidWords(s)}`;
};

/*
 * Items — what a desk's STATE holds worth finding (phase 2, Benj 2026-10-08): a finding, an open entry of the watch book,
 * a share, an app, a dataset, a log … Each desk names them with ONE provider beside Office.places():
 *   Office.placesFrom(ID, (state, part) => [{text, sub, route, anchor, words}, …])
 *     text    what the page shows for it, in the desk's own words (its text helpers): never a raw log line, a secret or
 *             a path the page doesn't show; ≤ ITEM_TEXT letters
 *     sub     the line under it after the desk's name (its section, its kind, its state)
 *     route   where it is ('#/<desk>' or a sub-route of the desk that opens what hides it: a tile, the entry's page)
 *     anchor  the element's data-place (Office.place()) — or its data-id: Office.reveal() takes that as a fallback
 *     words   more words it is found by (not shown)
 * The provider is called with every state (or part) that ARRIVES through Office.loadState() — the desk's own look, the
 * states kept for the reception and the badges, a later look — never by a request of its own, never per keystroke.
 * It runs when the page is idle after the state was drawn (or at once when the palette asks), ≤ 2 ms a state; what it
 * returns is kept (ITEM_CAP at most), the state itself is not. A state that came another way (the drill's certificate)
 * is handed in with Office.placesTook(ID, data, part). No state yet (the first paint): no items.
 */
const ITEM_CAP = 200;
const ITEM_TEXT = 80;
const itemProviders = new Map();    // desk -> provider
const itemLists = new Map();        // 'desk' or 'desk/part' -> its items (cleaned)
const itemWaiting = new Map();      // 'desk' or 'desk/part' -> {desk, part, data}: a state not read yet
let itemTimer = 0;
let itemIndex = null;

Office.placesFrom = function placesFrom(desk, provider) {
  if (typeof provider === 'function') itemProviders.set(desk, provider); else itemProviders.delete(desk);
  itemIndex = null;
};
/** A state that came another way than Office.loadState(): its items anew */
Office.placesTook = (desk, data, part) => itemsLater(desk, part || null, data);

/** An answer of Office.loadState() (seen: noteLook()'s word): a new state's items are read when the page is idle */
function stateItems(desk, part, j, seen) {
  if (!j || !j.ok || !seen || seen === 'older') return;
  if (seen === 'same' && itemLists.has(lookKey(desk, part))) return;      // the very state read already
  itemsLater(desk, part, 'part' in j ? j.part : j.state);
}
function itemsLater(desk, part, data) {
  if (!itemProviders.has(desk) || !data || typeof data !== 'object') return;
  itemWaiting.set(lookKey(desk, part), { desk, part, data });
  if (itemTimer) return;
  const done = () => { itemTimer = 0; if (readItems() && Office.paletteOpen()) renderPalette(); };
  itemTimer = typeof requestIdleCallback === 'function' ? requestIdleCallback(done, { timeout: 1000 }) : setTimeout(done, 30);
}
/** Read the states that wait: each desk's provider, its answer cleaned and kept. How many were read */
function readItems() {
  let n = 0;
  for (const [key, { desk, part, data }] of itemWaiting) {
    itemWaiting.delete(key);
    let list = [];
    try { list = itemProviders.get(desk)(data, part); } catch (e) { list = []; }      // a provider's slip costs its items only
    itemLists.set(key, cleanItems(desk, list));
    n++;
  }
  if (n) itemIndex = null;
  return n;
}

/** A text as an item shows it: one line, at most max letters (cut at a word) */
const itemText = (s, max) => {
  const v = (typeof s === 'string' || typeof s === 'number' ? String(s) : '').replace(/\s+/g, ' ').trim();
  if (v.length <= max) return v;
  const cut = v.slice(0, max - 2);
  return (cut.replace(/\s+\S*$/, '') || cut) + ' …';
};
const ITEM_ROUTE = /^#\/[a-z0-9_-]+(?:\/[^\s"'<>\\]*)?$/;
/** What a provider gave, as the search keeps it: text, a route to the desk itself, at most ITEM_CAP */
function cleanItems(desk, list) {
  const out = [];
  for (const x of Array.isArray(list) ? list : []) {
    if (out.length >= ITEM_CAP) break;
    if (!x || typeof x !== 'object') continue;
    const label = itemText(x.text, ITEM_TEXT);
    if (!label) continue;
    const own = typeof x.route === 'string' && (x.route === `#/${desk}` || x.route.startsWith(`#/${desk}/`)) && ITEM_ROUTE.test(x.route);
    out.push({ desk, kind: 'item', key: null, label, sub: itemText(x.sub, 120), words: itemText(x.words, 400),
      route: own ? x.route : `#/${desk}`, anchor: typeof x.anchor === 'string' && x.anchor ? x.anchor : null,
      text: null, crumb: null, act: null, tokens: null });
  }
  return out;
}
/** Every desk's items (the states waiting read first) */
function itemEntries() {
  if (itemWaiting.size) readItems();
  if (!itemIndex) itemIndex = [].concat(...itemLists.values());
  return itemIndex;
}

/** The office's own places: the reception, the help's parts, the language, its entry in Unraid */
function officePlaces() {
  const help = ['office', 'search', 'order', 'agent', 'dot', 'start', 'languages', 'security', 'report'].concat(Office.theme ? ['theme'] : [])   // theme-switch
    .concat(Office.size ? ['size'] : [])     // size-switch
    .map((x) => ({ kind: 'help', key: `help.${x}_title`, anchor: `help.${x}_title`, text: `help.${x}_text`, act: Office.help }));
  Office.places('', [
    { kind: 'desk', key: 'office.reception', route: '#/', anchor: null },
    { kind: 'setting', key: 'office.order_change', route: '#/', anchor: null },
    { kind: 'guide', key: 'help.title', anchor: null, act: Office.help },
    ...help,
    { kind: 'setting', key: 'office.language', anchor: null, act: languageDialog },
    { kind: 'setting', key: 'office.menu_title', anchor: null, act: menuNameDialog },
    { kind: 'setting', key: 'office.log', anchor: null, act: showLog },
    { kind: 'setting', key: 'office.report_menu', anchor: null, act: () => Office.reportDialog(), shown: () => !!CONFIG.report },
  ]);
}

const hiredDesk = (id) => !id || !!(Office.desks.get(id) || CONFIG.desks.find((d) => d.id === id) || {}).hired;

/**
 * Every place once: the desks themselves first, then what each lists; a term named like a section of its desk is that
 * section (a section the page may not draw — shown — keeps the term beside it: the search lists the one that is there).
 * Each place's texts: its explanation (text) and a guide's paragraphs (paras), with the anchor each lands on.
 */
function placeIndex() {
  if (searchIndex && indexStrings === Office.strings) return searchIndex;
  if (!placeLists.has('')) officePlaces();
  const all = [...(placeLists.get('') || [])];
  for (const d of CONFIG.desks) {
    all.push({ desk: d.id, kind: 'desk', key: 'name', route: `#/${d.id}`, anchor: null, text: null, crumb: null, act: null });
    all.push(...(placeLists.get(d.id) || []));
  }
  const kept = new Map();
  const full = (p, k) => (p.desk ? `${p.desk}.${k}` : k);
  searchIndex = [];
  for (const p of all) {
    if (!Office.has(full(p, p.key))) continue;
    const label = placeLabel(t(full(p, p.key)));
    if (!label) continue;
    const same = `${p.desk}|${foldText(label)}`;
    const first = kept.get(same);
    if (first) {                           // the same words twice on a desk: one result, a term's explanation joins it
      if (!first.text && p.text) first.text = p.text;
      if (!first.shown) continue;
    }
    const e = { ...p, full: full(p, p.key), label, same, tokens: null, texts: [] };
    if (!first) kept.set(same, e);
    searchIndex.push(e);
  }
  for (const e of searchIndex) {
    if (e.text && Office.has(full(e, e.text))) e.texts.push({ full: full(e, e.text), anchor: e.anchor });
    for (let i = 1; e.paras && i < 100 && Office.has(full(e, `${e.paras}.${i}`)); i++) {
      e.texts.push({ full: full(e, `${e.paras}.${i}`), anchor: `${e.paras}.${i}` });
    }
  }
  indexStrings = Office.strings;
  textHays.clear();
  return searchIndex;
}

/** A place's words: as the page shows them now, as kept in the office's language, and (once there) in every other */
function placeTokens(e) {
  if (e.tokens) return e.tokens;
  const texts = e.kind === 'item' ? [e.label, e.sub, e.words] : [e.label, placeWordsOf(Office.strings[e.full])];
  const w = e.kind !== 'item' && searchWords && searchWords[e.full];
  if (w && typeof w === 'object') texts.push(...Object.values(w).map(placeWordsOf));
  e.tokens = [...new Set(texts.flatMap((s) => foldText(s).split(' ')).filter(Boolean))];
  return e.tokens;
}

/** The other languages' words, asked once (on the first open): a day in the browser's cache, like the strings */
function placeWords() {
  if (wordsAsked) {
    if (searchWords) placeTexts();               // the texts failed after the words came: asked again at this open
    return wordsAsked;
  }
  wordsAsked = fetch(`${API}?a=places&v=${encodeURIComponent(`${CONFIG.stamp}-${CONFIG.version}`)}`)
    .then((r) => r.json())
    .then((j) => {
      if (!j || !j.ok || !j.words || typeof j.words !== 'object') return;
      searchWords = j.words;
      for (const e of searchIndex || []) e.tokens = null;
      if (Office.paletteOpen()) renderPalette();
      return placeTexts();
    })
    .catch(() => { wordsAsked = null; });          // asked again at the next open
  return wordsAsked;
}

/**
 * The texts' English words, right after the words (the office's own language has its texts in the strings already; an
 * English office asks nothing). Never with the first paint, never per keystroke; a day in the browser's cache.
 */
function placeTexts() {
  if (textsAsked || Office.lang === 'en') return textsAsked;
  textsAsked = fetch(`${API}?a=places&part=text&v=${encodeURIComponent(`${CONFIG.stamp}-${CONFIG.version}`)}`)
    .then((r) => r.json())
    .then((j) => {
      if (!j || !j.ok || !j.texts || typeof j.texts !== 'object') return;
      searchTexts = j.texts;
      textHays.clear();
      if (Office.paletteOpen()) renderPalette();
    })
    .catch(() => { textsAsked = null; });          // asked again at the next open (placeWords())
  return textsAsked;
}

/** A text as the search compares it: ' word word … ' (folded, each once) — in the office's language ('') or English */
function textHay(full, lang) {
  const k = `${lang}|${full}`;
  let hay = textHays.get(k);
  if (hay === undefined) {
    const v = lang ? searchTexts && searchTexts[full] : Office.strings[full];
    hay = v ? ` ${[...new Set(foldText(placeWordsOf(v)).split(' ').filter(Boolean))].join(' ')} ` : '';
    textHays.set(k, hay);
  }
  return hay;
}
/**
 * How well a query word meets a text: 4 a word of it, 3 a word's start, 2 inside a word (from TEXT_INSIDE letters: a long
 * text has many words — «gangs» in «Rundgangs», «lock» in «Glocke» are noise), 0 not (no typing errors)
 */
const TEXT_INSIDE = 6;
const textMeets = (q, w) => w === q || w.startsWith(q) || (q.length >= TEXT_INSIDE && w.includes(q));
const hayScore = (q, hay) => (!hay.includes(q) ? 0 : hay.includes(` ${q} `) ? 4 : hay.includes(` ${q}`) ? 3 : q.length >= TEXT_INSIDE ? 2 : 0);

/**
 * A place met in its texts: every query word meets the text (or the place's own words as typed), at least one the
 * text — the best text of the place (the office's language first, then English); null when none
 */
function textHit(e, qs, own) {
  let best = null;
  for (const tx of e.texts) {
    for (const lang of searchTexts && Office.lang !== 'en' ? ['', 'en'] : ['']) {
      const hay = textHay(tx.full, lang);
      if (!hay) continue;
      let sum = 0, met = 0;
      for (let i = 0; i < qs.length && sum >= 0; i++) {
        const s = hayScore(qs[i], hay);
        const o = own(i) >= 2 ? own(i) : 0;
        if (!s && !o) sum = -1;
        else { sum += Math.max(s, o); if (s) met++; }
      }
      if (sum > 0 && met && (!best || sum > best.sum)) best = { sum, tx, lang };
    }
  }
  return best;
}

/**
 * The sentence a text hit shows: ≤ TEXT_SNIPPET letters around the first word met (… where cut), the words met marked —
 * {text, marks: [[from, to], …]}. In the language it was met in; placeholders the page fills in read «…».
 */
const TEXT_SNIPPET = 80;
function textSnippet(full, lang, qs) {
  const v = lang ? searchTexts && searchTexts[full] : Office.strings[full];
  let s = typeof v === 'string' ? v : v && typeof v === 'object' ? String(v.other || Object.values(v).find((x) => typeof x === 'string') || '') : '';
  s = (lang ? s.replace(/[⟦⟧]/g, '') : unraidWords(s)).replace(/\{\w+\}/g, '…').replace(/\s+/g, ' ').trim();
  const met = [];
  for (const m of s.matchAll(/[\p{L}\p{M}\p{N}]+/gu)) {
    const w = foldText(m[0]);
    if (qs.some((q) => textMeets(q, w))) met.push([m.index, m.index + m[0].length]);
  }
  const room = TEXT_SNIPPET - 4;
  if (s.length <= TEXT_SNIPPET) return { text: s, marks: met };
  const at = met.length ? met[0][0] : 0;
  let from = Math.max(0, Math.min(at - 24, s.length - room));
  if (from > 0) {
    const sp = s.indexOf(' ', from);
    from = sp >= 0 && sp < at ? sp + 1 : Math.min(from, at);
  }
  let to = Math.min(s.length, from + room);
  if (to < s.length) {
    const sp = s.lastIndexOf(' ', to);
    if (sp > from && (!met.length || sp >= met[0][1])) to = sp;
  }
  const head = from > 0 ? '… ' : '';
  const text = head + s.slice(from, to) + (to < s.length ? ' …' : '');
  const shift = head.length - from;
  return { text, marks: met.filter(([a, b]) => a >= from && b <= to).map(([a, b]) => [a + shift, b + shift]) };
}

/** Typing errors: Damerau-Levenshtein (adjacent swaps count one), given up beyond max */
function typoDistance(a, b, max) {
  if (Math.abs(a.length - b.length) > max) return max + 1;
  let older = null, prev = Array.from({ length: b.length + 1 }, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    let best = i;
    for (let j = 1; j <= b.length; j++) {
      let v = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) v = Math.min(v, older[j - 2] + 1);
      cur[j] = v;
      best = Math.min(best, v);
    }
    if (best > max) return max + 1;
    older = prev;
    prev = cur;
  }
  return prev[b.length];
}

/** How well one word of the query meets one of a place: 4 the word, 3 its start, 2 inside it, 1 a typing error away, 0 not */
function wordScore(q, w) {
  if (w === q) return 4;
  if (w.startsWith(q)) return 3;
  if (q.length >= 3 && w.includes(q)) return 2;
  if (q.length < 4) return 0;                      // shorter: too many words are one letter away
  const max = q.length >= 8 ? 2 : 1;
  for (const len of new Set([q.length - 1, q.length, q.length + 1].map((n) => Math.min(n, w.length)))) {
    if (len > 0 && typoDistance(q, w.slice(0, len), max) <= max) return 1;
  }
  return 0;
}

/** Is a place drawn now (its desk's rule: shown())? A rule that slips counts as drawn */
const placeShown = (e) => { try { return !e.shown || e.shown() !== false; } catch (err) { return true; } };

/**
 * The places and items for a query, best first (hired desks before the others), at most PLACES_SHOWN. Every word of the
 * query must meet a word of the place (in any language); typing errors count only while nothing meets the query as it
 * is typed. A place met word by word (each word as it is or at its start) comes before the items, an item before a place
 * met only inside its words; a place and an item that lead to the same spot are one result (the better one). A place
 * met only in its texts (its explanation, a guide's paragraphs — the words as typed) comes last, its sentence shown.
 * A place its desk doesn't draw now (shown) is left out.
 */
function findPlaces(query) {
  const qs = foldText(query).split(' ').filter(Boolean);
  if (!qs.length) return [];
  const whole = qs.join(' ');
  const here = Office.current ? Office.current.id : '';
  const out = [];
  const best = (q, words) => {
    let b = 0;
    for (const w of words) {
      const s = wordScore(q, w);
      if (s > b) { b = s; if (s === 4) break; }
    }
    return b;
  };
  const items = itemEntries();                              // the providers have read the states that came: shown() knows them
  for (const e of [...placeIndex(), ...items]) {
    const item = e.kind === 'item';
    if (item && !hiredDesk(e.desk)) continue;               // a desk let go: what it held is gone with it
    if (!item && !placeShown(e)) continue;                  // not drawn now: nothing to go to
    const words = placeTokens(e);
    let sum = 0, typo = false, low = 4, missed = -1;
    for (let i = 0; i < qs.length; i++) {
      const b = best(qs[i], words);
      if (!b) { sum = -1; missed = i; break; }
      sum += b;
      low = Math.min(low, b);
      typo = typo || b === 1;
    }
    if (sum < 0) {
      // not met by its own words: its texts (its own words count as typed for the other query words)
      const own = [];
      const hit = e.texts && e.texts.length
        ? textHit(e, qs, (i) => (i === missed ? 0 : own[i] !== undefined ? own[i] : (own[i] = best(qs[i], words)))) : null;
      if (!hit) continue;
      const score = (hit.sum / qs.length / 4) * PLACE_WEIGHT.text + (e.desk && e.desk === here ? 0.2 : 0);
      out.push({ e, score, rank: score - 2, typo: false, text: hit, hired: hiredDesk(e.desk) });
      continue;
    }
    let score = (sum / qs.length / 4) * PLACE_WEIGHT[e.kind];
    if (foldText(e.label) === whole) score += 0.3;            // the very words the page shows
    else if (foldText(e.label).startsWith(whole)) score += 0.15;
    if (e.desk && e.desk === here) score += 0.2;              // the desk shown first
    const rank = score + (typo ? 0 : !item && low >= 3 ? 2 : 1);
    out.push({ e, score, rank, typo, hired: hiredDesk(e.desk) });
  }
  // typing errors only while nothing meets as typed by its own words (a word in some text doesn't hide them)
  const typed = out.some((x) => !x.typo && !x.text) ? out.filter((x) => !x.typo) : out;
  typed.sort((a, b) => (b.hired - a.hired) || (b.rank - a.rank) || (a.e.label.length - b.e.label.length)
    || (Office.deskRank(a.e.desk) - Office.deskRank(b.e.desk)));
  const spots = new Set();
  const sames = new Set();
  return typed.filter(({ e, text }) => {
    if (e.same) {                            // a term and the section of its name: the one that is there
      if (sames.has(e.same)) return false;
      sames.add(e.same);
    }
    const anchor = text ? text.tx.anchor : e.anchor;
    if (!anchor) return true;
    const spot = `${e.desk}|${e.route}|${anchor}`;
    if (spots.has(spot)) return false;
    spots.add(spot);
    return true;
  }).slice(0, PLACES_SHOWN).map(({ e, score, hired, text }) => {
    const r = { desk: e.desk, key: e.key, kind: e.kind, route: e.route, anchor: e.anchor, part: e.part, act: e.act, label: e.label,
      hired, score, crumb: placeCrumb(e), detail: placeDetail(e), marks: null, text: null };
    if (text) {
      const snip = textSnippet(text.tx.full, text.lang, qs);
      // a paragraph: the sentence is the spot, the guide's own anchor the part around it
      if (text.tx.anchor !== e.anchor) Object.assign(r, { anchor: text.tx.anchor, part: e.anchor || e.part });
      Object.assign(r, { detail: snip.text, marks: snip.marks, text: e.desk ? text.tx.full.slice(e.desk.length + 1) : text.tx.full });
    }
    return r;
  });
}

/** «Mr. Backupsy › Set up», «… › How to read this page»; a desk none (its role is the line under it) */
function placeCrumb(e) {
  if (!e.desk) return [t('office.name'), e.kind === 'help' && e.act ? t('help.title') : ''].filter(Boolean).join(' › ');
  if (e.kind === 'desk') return '';
  if (e.kind === 'item') return [t(`${e.desk}.name`), e.sub].filter(Boolean).join(' › ');
  const parts = [t(`${e.desk}.name`)];
  if (e.crumb && Office.has(`${e.desk}.${e.crumb}`)) parts.push(placeLabel(t(`${e.desk}.${e.crumb}`)));
  if (e.kind === 'help') parts.push(t('common.page_help'));
  return parts.join(' › ');
}
/** The beginning of a term's explanation (a desk's: its role) */
function placeDetail(e) {
  const text = e.kind === 'desk' && e.desk ? 'role' : e.text;
  const key = text ? (e.desk ? `${e.desk}.${text}` : text) : null;
  if (!key || !Office.has(key)) return '';
  const s = placeLabel(t(key));
  return s.length > 110 ? s.slice(0, 108).replace(/\s+\S*$/, '') + ' …' : s;
}

// the palette: a field and its list under the magnifier (role combobox / listbox), like Office.menu() appended to #sso
let palette = null;
Office.paletteOpen = () => !!(palette && !palette.box.hidden);
const isMac = () => /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
const shortcutText = () => (isMac() ? '⌘K' : 'Ctrl+K');

/** A magnifier drawn with the text's colour */
function magnifier() {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 16 16');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const c = document.createElementNS(NS, 'circle');
  [['cx', '6.8'], ['cy', '6.8'], ['r', '4.6']].forEach(([k, v]) => c.setAttribute(k, v));
  const l = document.createElementNS(NS, 'path');
  l.setAttribute('d', 'M10.3 10.3 14.2 14.2');
  [c, l].forEach((n) => { n.setAttribute('fill', 'none'); n.setAttribute('stroke', 'currentColor'); n.setAttribute('stroke-width', '1.7'); n.setAttribute('stroke-linecap', 'round'); svg.appendChild(n); });
  return svg;
}

/** The magnifier in the top line, before the messenger's word */
function searchButton() {
  const state = $('#sso-state');
  if (!state || $('#sso-search')) return;
  const b = el('button', 'search-open');
  b.type = 'button';
  b.id = 'sso-search';
  b.title = `${t('search.open')} (${shortcutText()})`;
  b.setAttribute('aria-label', t('search.open'));
  b.setAttribute('aria-haspopup', 'listbox');
  b.setAttribute('aria-expanded', 'false');
  b.appendChild(magnifier());
  b.onclick = (e) => { e.stopPropagation(); if (Office.paletteOpen()) closePalette(true); else openPalette(); };
  state.parentNode.insertBefore(b, state);
}

function buildPalette() {
  const box = el('div', 'palette');
  box.id = 'sso-palette';
  box.hidden = true;
  const field = el('div', 'palette-field');
  const input = el('input', 'palette-input');
  input.type = 'text';
  input.id = 'sso-palette-input';
  input.autocomplete = 'off';
  input.spellcheck = false;
  input.dataset.keep = '1';
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', 'sso-palette-list');
  input.setAttribute('aria-expanded', 'false');
  field.append(magnifier(), input);
  const list = el('ul', 'palette-list');
  list.id = 'sso-palette-list';
  list.setAttribute('role', 'listbox');
  const foot = el('div', 'palette-foot');
  const count = el('span', 'palette-count');
  count.setAttribute('aria-live', 'polite');
  const hint = el('span', 'palette-hint');
  foot.append(count, hint);
  box.append(field, list, foot);
  ROOT.appendChild(box);
  const p = { box, input, list, count, hint, results: [], active: -1, query: null };
  input.addEventListener('input', () => renderPalette());
  input.addEventListener('keydown', (e) => {
    const n = p.results.length;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (n) paletteActive(p.active < 0 ? (e.key === 'ArrowDown' ? 0 : n - 1) : (p.active + (e.key === 'ArrowDown' ? 1 : n - 1)) % n);
    } else if (e.key === 'Home' && n && e.ctrlKey) {
      e.preventDefault();
      paletteActive(0);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (n) choosePlace(p.active < 0 ? 0 : p.active);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      closePalette(true);
    } else if (e.key === 'Tab') {
      closePalette(false);                 // the focus moves on, the palette goes
    }
  });
  return p;
}

function openPalette() {
  if (Office.dialogOpen()) return;
  hideMenu();
  hideTip();
  if (!palette) palette = buildPalette();
  // its words in the language shown now (the language may have changed since it was built)
  palette.input.placeholder = t('search.placeholder');
  palette.input.setAttribute('aria-label', t('search.open'));
  palette.list.setAttribute('aria-label', t('search.label'));
  palette.hint.textContent = t('search.hint');
  palette.box.hidden = false;
  const b = $('#sso-search');
  if (b) b.setAttribute('aria-expanded', 'true');
  placePalette();
  palette.query = null;
  renderPalette();
  palette.input.focus();
  palette.input.select();
  placeWords();                            // the other languages' words — the first open asks, never the first paint
}

/** Close it; back to the magnifier (Esc), or the focus where it goes (a place chosen, a click elsewhere) */
function closePalette(refocus) {
  if (!Office.paletteOpen()) return;
  palette.box.hidden = true;
  palette.input.setAttribute('aria-expanded', 'false');
  const b = $('#sso-search');
  if (b) {
    b.setAttribute('aria-expanded', 'false');
    if (refocus) b.focus();
  }
}

/** Under the magnifier, right-aligned to it; on a phone the screen's width */
function placePalette() {
  const b = $('#sso-search');
  const s = fixedSpace();       // the office's own px (the text-size switch's zoom)
  const br = b && b.getBoundingClientRect ? b.getBoundingClientRect() : null;
  const r = br ? { bottom: br.bottom / s.k, right: br.right / s.k } : { bottom: 0, right: s.w };
  const w = Math.min(560, s.w - 16);
  palette.box.style.width = w + 'px';
  palette.box.style.left = Math.max(8, Math.min(r.right - w, s.w - w - 8)) + 'px';
  palette.box.style.top = Math.max(8, r.bottom + 6) + 'px';
}

function renderPalette() {
  const p = palette;
  const query = String(p.input.value || '');
  const found = query.trim() ? findPlaces(query) : [];
  p.results = found;
  p.list.innerHTML = '';
  found.forEach((r, i) => {
    const li = el('li', 'palette-item' + (r.hired ? '' : ' unhired'));
    li.id = `sso-palette-opt-${i}`;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    const icon = el('span', 'palette-icon');
    icon.appendChild(Office.deskIcon(r.desk));
    const text = el('span', 'palette-text');
    text.appendChild(el('span', 'palette-name', r.label));
    if (r.crumb) text.appendChild(el('span', 'palette-crumb', r.crumb));
    if (r.marks) {                         // the sentence a text hit met, the words met marked
      const d = el('span', 'palette-detail snippet');
      let at = 0;
      for (const [a, b] of r.marks) {
        if (a > at) d.append(r.detail.slice(at, a));
        d.appendChild(el('mark', '', r.detail.slice(a, b)));
        at = b;
      }
      if (at < r.detail.length) d.append(r.detail.slice(at));
      text.appendChild(d);
    } else if (r.detail) text.appendChild(el('span', 'palette-detail', r.detail));
    const chips = el('span', 'palette-chips');
    chips.appendChild(el('span', 'chip quiet', t(`search.kind.${r.kind}`)));
    if (!r.hired) chips.appendChild(el('span', 'chip quiet', t('search.not_hired')));
    li.append(icon, text, chips);
    li.addEventListener('mousedown', (e) => e.preventDefault());       // the field keeps the focus
    li.addEventListener('mousemove', () => { if (p.active !== i) paletteActive(i); });
    li.addEventListener('click', () => choosePlace(i));
    p.list.appendChild(li);
  });
  p.list.hidden = !found.length;
  p.input.setAttribute('aria-expanded', String(found.length > 0));
  // «3 places · 2 items»
  const items = found.filter((r) => r.kind === 'item').length;
  const places = found.length - items;
  p.count.textContent = !query.trim() ? '' : !found.length ? t('search.none', { q: query.trim() })
    : [places || !items ? t('search.results', { n: places }) : '', items ? t('search.items', { n: items }) : ''].filter(Boolean).join(' · ');
  p.active = -1;
  p.input.removeAttribute('aria-activedescendant');
  if (found.length) paletteActive(0);
}

function paletteActive(i) {
  const p = palette;
  p.active = i;
  [...p.list.children].forEach((li, j) => {
    li.classList.toggle('active', j === i);
    li.setAttribute('aria-selected', String(j === i));
  });
  const li = p.list.children[i];
  if (li) {
    p.input.setAttribute('aria-activedescendant', li.id);
    if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
  }
}

function choosePlace(i) {
  const r = palette && palette.results[i];
  if (!r) return;
  closePalette(false);
  Office.goToPlace(r);
}

/**
 * Go to a place: the office's own act (a dialog), else the desk's route — a desk not working here: the Team Lead's «The
 * team» (route() would say so too) — then Office.reveal(). On the page shown already (no sub-route) nothing is drawn anew.
 */
Office.goToPlace = function goToPlace(r) {
  if (r.act) {
    r.act();
    if (r.anchor) Office.reveal(r.anchor, { name: r.label, part: r.part });
    return;
  }
  if (!r.hired) {
    Office.toast(t('office.not_hired', { name: t(`${r.desk}.name`) }));
    const gen = routeGen;
    Office.go('#/caretaker');
    Office.reveal('team', { since: gen, name: t('caretaker.team') });
    return;
  }
  const here = (location.hash || '#/') === r.route && r.route.split('/').length <= 2;
  if (here && r.anchor) { Office.reveal(r.anchor, { name: r.label, part: r.part }); return; }
  const gen = routeGen;
  Office.go(r.route);
  if (r.anchor) Office.reveal(r.anchor, { since: gen, name: r.label, part: r.part });
};

/**
 * Bring a place into view: [data-place="<anchor>"] in the office — else [data-id="<anchor>"] (rows that carry their id
 * already, like the watchman's posture tips: an item's anchor may name it) — at once, or as soon as the page has drawn it
 * (desks draw before their state arrives: watched for ≤ 10 s; since = only a page drawn after that route counts). Every
 * <details> above it opens; inside a folded group (.group.closed) its head stands for it (the desk opens its groups);
 * it scrolls under Unraid's menu, is marked a moment and takes the focus. For REVEAL_SETTLE more a page drawn anew (the
 * state arrived) is followed, and the place is kept where it was put while the page settles above it (a desk's head
 * that grows or shrinks when the new look arrives, «As of …» going: 11 px above the top on a phone, 2026-10-08) —
 * the user scrolling, clicking or typing ends all of it. part (the place's part of the page, Office.places()): when the
 * place isn't drawn within REVEAL_PART, that part is brought into view meanwhile; when it never comes, the part is
 * marked before search.not_there says so.
 */
const REVEAL_WAIT = 10000;
const REVEAL_SETTLE = 6000;
const REVEAL_PART = 1500;
let revealing = null;
Office.reveal = function reveal(anchor, opts) {
  if (revealing) revealing.stop();
  if (!anchor) return;
  const { since, name, part } = opts || {};
  const quote = (a) => String(a).replace(/["\\]/g, '\\$&');
  const quoted = quote(anchor);
  const sel = `[data-place="${quoted}"]`;
  const byId = `[data-id="${quoted}"]`;
  const partSel = part && part !== anchor ? `[data-place="${quote(part)}"]` : null;
  const me = {};
  let hit = null, shownNode = null, want = null, found = false, timer = 0, partTimer = 0, obs = null, sizes = null, done = false, frame = false;
  const user = ['wheel', 'touchstart', 'pointerdown', 'keydown'];
  const stop = () => {
    done = true;
    clearTimeout(timer);
    clearTimeout(partTimer);
    if (obs) obs.disconnect();
    if (sizes) sizes.disconnect();
    user.forEach((ev) => document.removeEventListener(ev, stop, true));
    if (revealing === me) revealing = null;
  };
  me.stop = stop;
  revealing = me;
  const drawn = () => !(since !== undefined && routeGen === since);
  // the place moved since it was put (the page settled above it): put back — retargeted while the smooth scroll runs
  const keep = () => {
    if (done || !shownNode || !shownNode.isConnected || want === null) return;
    const now = placeTop(shownNode);
    if (now === null || Math.abs(now - want) <= 1) return;
    const moving = Math.abs(window.scrollY - want) > 1;
    want = now;
    window.scrollTo({ top: now, behavior: moving && !reducedMotion() ? 'smooth' : 'auto' });
  };
  const keepSoon = () => {
    if (frame || done) return;
    frame = true;
    (typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (f) => setTimeout(f, 16))(() => { frame = false; keep(); });
  };
  const look = () => {
    if (done || !drawn()) return;
    if (hit && hit.isConnected) { keepSoon(); return; }
    const node = ROOT.querySelector(sel) || ROOT.querySelector(byId);
    if (!node) return;
    const first = !found;
    found = true;
    hit = node;
    clearTimeout(partTimer);
    const folded = node.closest('.group.closed');
    shownNode = (folded && folded !== node && folded.querySelector('.group-head')) || node;
    want = showPlace(shownNode, first);
    if (first) {                         // found: follow a page drawn anew a little longer, then let go
      clearTimeout(timer);
      timer = setTimeout(() => { keep(); stop(); }, REVEAL_SETTLE);
    }
  };
  const partNode = () => (partSel && drawn() ? ROOT.querySelector(partSel) : null);
  setTimeout(() => { if (!done) user.forEach((ev) => document.addEventListener(ev, stop, true)); }, 0);
  if (typeof MutationObserver === 'function') {
    obs = new MutationObserver(look);
    obs.observe(ROOT, { childList: true, subtree: true });
  }
  if (typeof ResizeObserver === 'function') {          // what grows or shrinks without a node added (a line wraps anew)
    sizes = new ResizeObserver(() => { if (hit) keepSoon(); });
    sizes.observe(ROOT);
  }
  if (partSel) {
    partTimer = setTimeout(() => {                     // not drawn yet: its part in view meanwhile (no mark, no focus)
      const p = !found && !done && partNode();
      if (p) placeScroll(p, true);
    }, REVEAL_PART);
  }
  timer = setTimeout(() => {
    if (!found && !done) {
      const p = partNode();
      if (p) showPlace(p, false);
      Office.toast(t('search.not_there', { name: name || '' }));
    }
    stop();
  }, REVEAL_WAIT);
  look();
};

const reducedMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
/**
 * Where the page must stand for a node to sit 12 px under what Unraid keeps fixed at the top (its menu when sticky — on
 * the desktop; on a phone it is static: 12 px under the window's top), as far as the page can scroll; null in a dialog
 */
function placeTop(node) {
  if (node.closest('.dialog')) return null;
  const menu = document.getElementById('menu');
  const above = menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.offsetHeight : 0;
  const top = node.getBoundingClientRect().top + window.scrollY - above - 12;
  const doc = document.documentElement;
  const most = doc && doc.scrollHeight ? Math.max(0, doc.scrollHeight - window.innerHeight) : Infinity;
  return Math.round(Math.max(0, Math.min(top, most)));
}
/** Scroll there (a dialog: its middle); the page's top it went to, or null */
function placeScroll(node, smooth) {
  const top = placeTop(node);
  if (top === null) { node.scrollIntoView({ block: 'center' }); return null; }
  window.scrollTo({ top, behavior: smooth && !reducedMotion() ? 'smooth' : 'auto' });
  return top;
}

function showPlace(node, first) {
  for (let d = node.closest('details'); d; d = d.parentElement ? d.parentElement.closest('details') : null) {
    if (!d.open) d.open = true;
  }
  const top = placeScroll(node, first);
  node.classList.add('place-hit');
  setTimeout(() => node.classList.remove('place-hit'), 1800);
  if (!node.matches('a[href], button, input, select, textarea, summary, [tabindex]')) {
    node.setAttribute('tabindex', '-1');
    node.addEventListener('blur', () => node.removeAttribute('tabindex'), { once: true });
  }
  node.focus({ preventScroll: true });
  return top;
}

/** ⌘K / Ctrl+K while the office has the focus — or nothing outside it was clicked last (Unraid's header keeps its own) */
let pointerInOffice = true;
function searchKeys() {
  document.addEventListener('pointerdown', (e) => {
    pointerInOffice = ROOT.contains(e.target);
    if (Office.paletteOpen() && !palette.box.contains(e.target) && !e.target.closest('#sso-search')) closePalette(false);
  }, true);
  window.addEventListener('keydown', (e) => {
    if (!(e.metaKey || e.ctrlKey) || e.altKey || e.shiftKey || String(e.key).toLowerCase() !== 'k') return;
    const f = document.activeElement;
    const inOffice = (f && f !== document.body && f !== document.documentElement) ? ROOT.contains(f) : pointerInOffice;
    if (!inOffice || Office.dialogOpen()) return;
    e.preventDefault();
    e.stopPropagation();
    if (Office.paletteOpen()) closePalette(true); else openPalette();
  }, true);
  window.addEventListener('resize', () => { if (Office.paletteOpen()) placePalette(); });
}

Office.search = {
  open: openPalette, close: () => closePalette(true), find: findPlaces, words: placeWords,
  /** the texts' English words as asked after the words (for the tests): a promise, or null when none were asked */
  texts: () => textsAsked,
  /** a desk's items as the search keeps them now (for the tests) */
  items: (desk) => itemEntries().filter((e) => !desk || e.desk === desk).map(({ tokens, ...e }) => ({ ...e })),
};

// ------------------------------------------------------------------ start
async function start() {
  await loadStrings(pickLanguage());
  rememberLanguage();
  if (CONFIG.agent) Office.setAgent(CONFIG.agent);      // the array stopped, the night shift: said before any desk asks
  footer();
  $('#sso-more').onclick = officeMenu;
  $('#sso-state').onclick = Office.help;
  searchButton();                      // the search: the magnifier and ⌘K / Ctrl+K — its index and words only when opened
  searchKeys();
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideMenu(); });
  initTips();
  // a mouse button or finger down: a new picture waits until the click is done (calm())
  document.addEventListener('pointerdown', () => { pressed = Date.now(); }, true);
  ['pointerup', 'pointercancel'].forEach((ev) => document.addEventListener(ev, () => setTimeout(() => { pressed = 0; }, 0), true));
  window.addEventListener('scroll', relaxDesk, { passive: true });
  window.addEventListener('hashchange', route);
  route();
  // desks that want to know something on every page (the caretaker's badge), unless the office is locked
  for (const d of Office.desks.values()) if (d.started) d.started();
  claimArm();
  setInterval(() => {
    claimLook();                    // a tip's key: the one ask on its own, when due (src/supporter.php)
    if (document.hidden || Office.dialogOpen() || Office.menuOpen()) return;
    if (Office.current && Office.current.poll) Office.current.poll();
    else if (!Office.agent.running) agentLook();      // the reception: is the messenger back, how is the night shift
  }, POLL);
  document.addEventListener('visibilitychange', () => {
    claimLook();
    if (document.hidden || Office.dialogOpen()) return;
    if (Office.current && Office.current.poll) Office.current.poll();
    else if (!Office.agent.running) agentLook();
  });
}

document.addEventListener('DOMContentLoaded', start);
})();
