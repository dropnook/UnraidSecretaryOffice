// The office's page built from the repository's files, served on 127.0.0.1 with a stub API - for the click test
// (tools/ui-clicks.mjs) and anyone who wants to look at a desk in a real browser without a server.
//
// What it serves:
//   /SecretaryOffice                 the page as src/page.php's officeBody() makes it (the config built here the same
//                                    way: every desk of public/desks, all hired, the agent at work)
//   /plugins/unraid-secretary-office/<file>   public/<file> (core.js, office.css, the desks, their lang files)
//   …/api.php?a=strings&lang=xx      the strings as src/desks.php officeStrings() merges them (English filled in)
//   …/api.php?a=state|part           the stub state of tests/ui/states/<desk>[-<part>].json; every look a second newer
//                                    than the last (its `time`), so each one is a new look the desk draws again
//   …/api.php?a=places               no words (the search isn't what this is for)
//   POST …/api.php                   noted (harness.posts); answered from tests/ui/states/post/<desk>.<action>.json when
//                                    there, else {ok: true} - always with the agent, like the real api.php
// No PHP, no dependencies: node's own http only. Nothing here touches a server.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO = path.resolve(HERE, '..', '..');
const PUBLIC = path.join(REPO, 'public');
const BASE = '/plugins/unraid-secretary-office/';
const VERSION = (/const OFFICE_VERSION = '([^']+)'/.exec(fs.readFileSync(path.join(REPO, 'src', 'bootstrap.php'), 'utf8')) || [])[1] || '0.0.0';
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.html': 'text/html' };

const readJson = (file) => { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { return null; } };

/** Every desk as src/desks.php officeDesks() lists it (order, then id) */
export function desks() {
  return fs.readdirSync(path.join(PUBLIC, 'desks')).filter((id) => /^[a-z][a-z0-9_-]{0,31}$/.test(id)
    && fs.existsSync(path.join(PUBLIC, 'desks', id, 'desk.js')) && readJson(path.join(PUBLIC, 'desks', id, 'desk.json')))
    .map((id) => {
      const m = readJson(path.join(PUBLIC, 'desks', id, 'desk.json'));
      return { id, order: m.order ?? 100, icon: m.icon ?? '•', refresh_after: m.refresh_after ?? 300, parts: m.parts || {},
        css: fs.existsSync(path.join(PUBLIC, 'desks', id, 'desk.css')), always: !!m.always, training: !!m.training,
        with: typeof m.with === 'string' && m.with !== id ? m.with : null };
    })
    .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));
}

function languages() {
  return fs.readdirSync(path.join(PUBLIC, 'lang')).filter((f) => /^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\.json$/.test(f))
    .map((f) => ({ code: f.replace(/\.json$/, ''), name: ((readJson(path.join(PUBLIC, 'lang', f)) || {})._meta || {}).name || f }))
    .sort((a, b) => a.code.localeCompare(b.code));
}

function strings(code) {
  const layer = (c) => {
    const s = { ...(readJson(path.join(PUBLIC, 'lang', `${c}.json`)) || {}) };
    for (const d of desks()) {
      for (const [k, v] of Object.entries(readJson(path.join(PUBLIC, 'desks', d.id, 'lang', `${c}.json`)) || {})) if (k !== '_meta') s[`${d.id}.${k}`] = v;
    }
    return s;
  };
  const en = layer('en');
  return code === 'en' ? en : { ...en, ...layer(code) };
}

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/**
 * Start the harness. opts: port (0 = any free one), statesDir (default tests/ui/states), later (ms: the shown desk's
 * look comes stale and the new one that many ms later - off by default).
 * Returns { url, posts, close(), setState(desk, state) }.
 */
export async function startHarness(opts = {}) {
  const statesDir = opts.statesDir || path.join(HERE, 'states');
  const list = desks();
  const stamp = Math.floor(Date.now() / 1000);
  const agent = () => ({ running: true, version: VERSION, pid: 4242, started: stamp, host: 'Tower', desks: list.map((d) => d.id), pulse: Math.floor(Date.now() / 1000) });
  const config = () => ({
    version: VERSION, host: 'Tower',
    desks: list.map((d) => ({ ...d, hired: true, avatar: fs.existsSync(path.join(PUBLIC, 'desks', d.id, 'avatar.svg')) ? `${BASE}desks/${d.id}/avatar.svg?v=${stamp}` : null })),
    staff_order: list.map((d) => d.id), languages: languages(), stamp,
    tip_url: '', support_url: '', sponsor_url: '', report: false, report_images: ['png', 'jpeg'], issues_url: '', forum_url: '',
    supporter: null, base: BASE, reception_icon: `${BASE}assets/reception.svg?v=${stamp}`, agent: agent(),
    theme_switch: true, size_switch: true, csrf: 'harness', array: 'Started', menu_name: 'Sekretariat', menu_default: 'Sekretariat',
    menu_max: 24, menu_page: 'SecretaryOffice', menu_place: 'tasks', unraid_lang: '', unraid_words: {}, lang_seen: null,
  });
  const overrides = new Map();
  const looks = new Map();          // desk[/part] -> how often asked: each look a second newer
  const posts = [];
  const stateOf = (name) => (overrides.has(name) ? overrides.get(name) : readJson(path.join(statesDir, `${name}.json`)));

  function page() {
    const css = [`assets/office.css`, `assets/theme-switch.css`, `assets/size-switch.css`, ...list.filter((d) => d.css).map((d) => `desks/${d.id}/desk.css`)];
    const js = [`assets/core.js`, `assets/theme-switch.js`, `assets/size-switch.js`, ...list.map((d) => `desks/${d.id}/desk.js`)];
    const json = JSON.stringify(config()).replace(/</g, '\\u003c').replace(/&/g, '\\u0026');
    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secretary Office (harness)</title>
<style>html,body{margin:0;padding:0}body{background:var(--harness-bg,#1c1b1b);padding:16px 10px}</style>
${css.map((f) => `<link rel="stylesheet" href="${esc(BASE + f)}?v=${stamp}">`).join('\n')}
</head><body>
<div class="sso in-unraid" id="sso">
<script>(function(){try{var t=localStorage.getItem('office.theme');if(t==='dark'||t==='light'){document.getElementById('sso').setAttribute('data-theme',t);document.body.style.setProperty('--harness-bg',t==='light'?'#f2f2f2':'#1c1b1b')}}catch(e){}})()</script>
<script>(function(){try{var s=localStorage.getItem('office.size');if(s==='medium'||s==='large'){document.getElementById('sso').setAttribute('data-size',s)}}catch(e){}})()</script>
<header class="topbar">
  <nav class="desk-tabs" id="sso-tabs" aria-label="Desks"></nav>
  <div class="topbar-right">
    <button class="agent-state" id="sso-state" type="button"><span class="agent-label" id="sso-state-label"></span><span class="dot" id="sso-dot"></span></button>
    <button class="more" id="sso-more" type="button" aria-label="More">⋯</button>
  </div>
</header>
<p class="notice" id="sso-notice" hidden></p>
<main class="page" id="sso-desk"></main>
<footer class="footer" id="sso-footer"></footer>
<div class="menu" id="sso-menu" hidden></div>
<div class="dialog-backdrop" id="sso-dialog-backdrop" hidden>
  <div class="dialog" id="sso-dialog" role="dialog" aria-modal="true" aria-labelledby="sso-dialog-title">
    <h3 id="sso-dialog-title"></h3>
    <div class="dialog-body" id="sso-dialog-body"></div>
    <div class="dialog-foot" id="sso-dialog-foot"></div>
  </div>
</div>
<div class="toasts" id="sso-toasts" aria-live="polite"></div>
</div>
<script id="sso-config" type="application/json">${json}</script>
${js.map((f) => `<script src="${esc(BASE + f)}?v=${stamp}"></script>`).join('\n')}
</body></html>`;
  }

  function answer(res, status, body, type = 'application/json') {
    res.writeHead(status, { 'Content-Type': `${type}; charset=utf-8`, 'Cache-Control': 'no-store' });
    res.end(typeof body === 'string' ? body : JSON.stringify(body));
  }

  function look(q) {
    const desk = q.get('desk') || '';
    const part = q.a === 'part' ? (q.get('part') || '') : '';
    const d = list.find((x) => x.id === desk);
    if (!d) return { ok: false, error: { key: 'unknown_desk', params: { desk } } };
    const name = part ? `${desk}-${part}` : desk;
    const s = stateOf(name);
    const n = (looks.get(name) || 0) + 1;
    looks.set(name, n);
    let data = s;
    if (s && typeof s === 'object' && !Array.isArray(s)) data = { ...s, time: (typeof s.time === 'number' ? s.time : stamp) + n };
    const after = part ? ((d.parts[part] || {}).refresh_after ?? 600) : d.refresh_after;
    // opts.later (ms): the shown desk's look is stale, the agent looks again - the page asks once more (wait=1) and
    // draws that new look once the user is calm (core.js followLook()), like a real state older than refresh_after
    const later = !!(opts.later && !q.get('stored') && !q.get('wait') && !q.get('fresh'));
    const r = { ok: true, agent: agent(), age: data ? (later ? after + 5 : 5) : null, stale: !data || later, refreshing: later, refresh_after: after };
    r[part ? 'part' : 'state'] = data ?? null;
    return r;
  }

  const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const p = decodeURIComponent(u.pathname);
    if (p === '/' || p === '/SecretaryOffice') return answer(res, 200, page(), 'text/html');
    if (p === '/favicon.ico') { res.writeHead(204); return res.end(); }
    if (p === BASE + 'api.php') {
      if (req.method === 'POST') {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
          let j = {};
          try { j = JSON.parse(body); } catch (e) { /* noted as it is */ }
          const action = String(j.a || '');
          posts.push({ action, data: j, at: Date.now() });
          const canned = /^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_]*$/.test(action) ? readJson(path.join(statesDir, 'post', `${action}.json`)) : null;
          answer(res, 200, { ok: true, ...(canned || {}), agent: agent() });
        });
        return undefined;
      }
      const q = u.searchParams;
      q.a = q.get('a');
      if (q.a === 'strings') {
        const code = languages().some((l) => l.code === q.get('lang')) ? q.get('lang') : 'en';
        return answer(res, 200, { ok: true, lang: code, strings: strings(code) });
      }
      if (q.a === 'state' || q.a === 'part') {
        const r = look(q);
        if (opts.later && q.get('wait')) { setTimeout(() => answer(res, 200, r), opts.later); return undefined; }
        return answer(res, 200, r);
      }
      if (q.a === 'places') return answer(res, 200, q.get('part') === 'text' ? { ok: true, lang: 'en', texts: {} } : { ok: true, langs: languages().map((l) => l.code), words: {} });
      if (q.a === 'agent') return answer(res, 200, { ok: true, agent: agent() });
      if (q.a === 'log') return answer(res, 200, { ok: true, lines: [] });
      return answer(res, 404, { ok: false, error: { key: 'bad_request' } });
    }
    if (p.startsWith(BASE)) {
      const file = path.normalize(path.join(PUBLIC, p.slice(BASE.length)));
      if (!file.startsWith(PUBLIC + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return answer(res, 404, 'not found', 'text/plain');
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
      return fs.createReadStream(file).pipe(res);
    }
    // pictures of Unraid's and other plugins' a state names (icons): a blank one, never a 404 in the console
    if (/^\/(plugins|webGui|state)\/.*\.(png|jpe?g|svg|gif|ico)$/i.test(p)) {
      res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-store' });
      return res.end('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
    }
    return answer(res, 404, 'not found', 'text/plain');
  });
  await new Promise((ok) => server.listen(opts.port || 0, '127.0.0.1', ok));
  const url = `http://127.0.0.1:${server.address().port}/SecretaryOffice`;
  return {
    url, posts, desks: list,
    setState(name, s) { if (s === undefined) overrides.delete(name); else overrides.set(name, s); },
    close: () => new Promise((ok) => server.close(ok)),
  };
}

// run on its own: `node tests/ui/harness.mjs [port]` serves until Ctrl-C (to look at a desk by hand in a browser of your own)
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  startHarness({ port: Number(process.argv[2]) || 8099 }).then((h) => console.log(`harness: ${h.url}#/advisor`));
}
