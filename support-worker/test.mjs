// Tests for the support worker — Node 22, no packages, nothing real is contacted:
// PayPal is a fake behind a mocked fetch, KV a Map, the signing key a throw-away one.
//
//   node test.mjs                    (in support-worker/; PHP on PATH checks the keys like the office)
//   USO_SUPPORTER_PHP=/path/to/src/supporter.php node test.mjs
//                                    also checks the keys with the office's own officeSupporterCheck()
//   QR_OUT=/some/dir node test.mjs   writes the page's QR codes there (qr-*.svg + expected.json)
//
// Ends with "N passed, 0 failed" and exit code 0 when all is well.

import { generateKeyPairSync, createPublicKey, verify as nodeVerify } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, copyFileSync, mkdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const tmp = mkdtempSync(join(tmpdir(), 'uso-support-test-'));
copyFileSync(join(here, 'worker.js'), join(tmp, 'worker.mjs'));
const worker = (await import(pathToFileURL(join(tmp, 'worker.mjs')).href)).default;

let passed = 0;
let failed = 0;
function check(name, ok, detail = '') {
  if (ok) {
    passed++;
  } else {
    failed++;
    console.log(`FAIL ${name}${detail ? ` — ${typeof detail === 'string' ? detail : JSON.stringify(detail)}` : ''}`);
  }
}
const same = (name, want, got) => check(name, JSON.stringify(want) === JSON.stringify(got), { want, got });

/* ───────── keys: a throw-away pair (the office's real public key is in worker.js) ───────── */

const pair = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
const PRIVATE = pair.privateKey.export({ type: 'pkcs8', format: 'pem' });
const PRIVATE_SEC1 = pair.privateKey.export({ type: 'sec1', format: 'pem' });
const PUBLIC = pair.publicKey.export({ type: 'spki', format: 'pem' });
const other = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
const OTHER_PRIVATE = other.privateKey.export({ type: 'pkcs8', format: 'pem' });
const pubFile = join(tmp, 'public.pem');
writeFileSync(pubFile, PUBLIC);

/* ───────── a fake KV namespace ───────── */

class FakeKV {
  constructor() {
    this.map = new Map();
    this.writes = 0;
    this.failPut = false;
  }
  async get(key, type) {
    const e = this.map.get(key);
    if (!e) return null;
    const t = typeof type === 'object' && type ? type.type : type;
    return t === 'json' ? JSON.parse(e.value) : e.value;
  }
  async put(key, value, opts = {}) {
    if (this.failPut) throw new Error('KV put failed');
    if (typeof value !== 'string') throw new Error('KV: strings only here');
    this.writes++;
    this.map.set(key, { value, opts });
  }
  async delete(key) {
    this.map.delete(key);
  }
  dump() {
    return [...this.map.entries()].map(([k, v]) => `${k}=${v.value}`).join('\n');
  }
}

/* ───────── a fake PayPal API behind fetch ───────── */

const PAYER_EMAIL = 'payer.secret@example.com';
const PAYER_NAME = 'Pat Payer-Secret';
const PAYER_ID = 'PAYERID9XYZ';
const pp = { orders: new Map(), calls: [], tokens: 0, token: null, unauthorizedOnce: false, n: 0, down: false };

function ppJson(status, data) {
  return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
}
function ppError(status, name, issue) {
  return ppJson(status, { name, details: [{ issue }], debug_id: 'dbg123' });
}
function representation(o) {
  const unit = o.request.purchase_units[0];
  const b = o.behaviour;
  const amount = b.amount || unit.amount;
  const custom = 'customId' in b ? b.customId : unit.custom_id;
  const capture = {
    id: `CAP${o.id}`, status: b.captureStatus || 'COMPLETED', amount, final_capture: true,
    seller_receivable_breakdown: { gross_amount: amount }, create_time: new Date().toISOString(),
  };
  if (custom !== undefined && !b.omitCustom && !(b.omitCustomOnCapture && o.view === 'capture')) capture.custom_id = custom;
  const pu = {
    reference_id: unit.reference_id, amount, description: unit.description,
    payee: { email_address: 'benj@example.com', merchant_id: 'MERCHANT1' },
    shipping: { name: { full_name: PAYER_NAME }, address: { address_line_1: '1 Secret Street', country_code: 'CH' } },
    payments: { captures: o.captured ? [capture] : [] },
  };
  if (custom !== undefined && !b.omitCustom && !(b.omitCustomOnCapture && o.view === 'capture')) pu.custom_id = custom;
  return {
    id: o.id, intent: 'CAPTURE',
    status: o.captured ? (b.orderStatus || 'COMPLETED') : (b.notApproved ? 'CREATED' : 'APPROVED'),
    payment_source: { paypal: { email_address: PAYER_EMAIL, account_id: PAYER_ID, name: { given_name: 'Pat', surname: 'Payer-Secret' } } },
    payer: { name: { given_name: 'Pat', surname: 'Payer-Secret' }, email_address: PAYER_EMAIL, payer_id: PAYER_ID },
    purchase_units: [pu], links: [],
  };
}

async function fakeFetch(input, init = {}) {
  const url = new URL(typeof input === 'string' ? input : input.url);
  const method = (init.method || 'GET').toUpperCase();
  const headers = new Headers(init.headers || {});
  pp.calls.push({ method, path: url.pathname, headers, body: init.body });
  if (url.origin !== 'https://api-m.sandbox.paypal.com') throw new Error(`unexpected fetch to ${url.origin}`);
  if (pp.down) throw new TypeError('fetch failed');
  if (url.pathname === '/v1/oauth2/token') {
    const want = `Basic ${Buffer.from('test-client-id-0123456789:test-secret-0123456789').toString('base64')}`;
    if (method !== 'POST' || headers.get('authorization') !== want || init.body !== 'grant_type=client_credentials') {
      return ppJson(401, { error: 'invalid_client' });
    }
    pp.tokens++;
    pp.token = `A21-test-token-${pp.tokens}`;
    return ppJson(200, { access_token: pp.token, token_type: 'Bearer', expires_in: 32400 });
  }
  if (headers.get('authorization') !== `Bearer ${pp.token}`) return ppJson(401, { error: 'invalid_token' });
  if (pp.unauthorizedOnce) {
    pp.unauthorizedOnce = false;
    pp.token = 'expired';
    return ppJson(401, { error: 'invalid_token' });
  }
  if (method === 'POST' && url.pathname === '/v2/checkout/orders') {
    const request = JSON.parse(init.body);
    pp.n++;
    const id = `5O${String(pp.n).padStart(15, '0')}`;
    pp.orders.set(id, { id, request, captured: false, behaviour: {}, captureRequestIds: new Set() });
    return ppJson(201, { id, status: 'CREATED', links: [] });
  }
  let m = /^\/v2\/checkout\/orders\/([^/]+)\/capture$/.exec(url.pathname);
  if (method === 'POST' && m) {
    const o = pp.orders.get(decodeURIComponent(m[1]));
    if (!o) return ppError(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID');
    if (o.behaviour.notApproved) return ppError(422, 'UNPROCESSABLE_ENTITY', 'ORDER_NOT_APPROVED');
    if (o.behaviour.declined) return ppError(422, 'UNPROCESSABLE_ENTITY', 'INSTRUMENT_DECLINED');
    const rid = headers.get('paypal-request-id');
    if (o.captured && !(rid && o.captureRequestIds.has(rid))) return ppError(422, 'UNPROCESSABLE_ENTITY', 'ORDER_ALREADY_CAPTURED');
    o.captured = true;
    if (rid) o.captureRequestIds.add(rid);
    o.view = 'capture';
    return ppJson(201, representation(o));
  }
  m = /^\/v2\/checkout\/orders\/([^/]+)$/.exec(url.pathname);
  if (method === 'GET' && m) {
    const o = pp.orders.get(decodeURIComponent(m[1]));
    if (!o) return ppError(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID');
    o.view = 'get';
    return ppJson(200, representation(o));
  }
  return ppError(404, 'NOT_FOUND', 'NOT_FOUND');
}
globalThis.fetch = fakeFetch;

// what the worker logs — checked at the end: never a request body, a name or anything about a payer
const logged = [];
console.error = (...args) => logged.push(args.map(String).join(' '));

/* ───────── calling the worker ───────── */

const ORIGIN = 'https://support.test';
const kv = new FakeKV();
const baseEnv = {
  PAYPAL_ENV: 'sandbox',
  PAYPAL_CLIENT_ID: 'test-client-id-0123456789',
  PAYPAL_CLIENT_SECRET: 'test-secret-0123456789',
  SUPPORTER_KEY: PRIVATE,
  SUPPORTER_PUBLIC_KEY: PUBLIC,
  SUPPORT_KV: kv,
};
const waits = [];
const ctx = { waitUntil: (p) => waits.push(p), passThroughOnException() {} };
let ipCount = 0;
const nextIp = () => `2001:db8::${(++ipCount).toString(16)}`;

async function call(method, path, { body, headers = {}, ip, env = baseEnv, raw = false } = {}) {
  const h = { 'cf-connecting-ip': ip || nextIp(), ...headers };
  const init = { method, headers: h };
  if (body !== undefined) {
    init.body = raw ? body : JSON.stringify(body);
    if (!('content-type' in h)) h['content-type'] = 'application/json';
    if (!('origin' in h)) h.origin = ORIGIN;
  }
  const res = await worker.fetch(new Request(ORIGIN + path, init), env, ctx);
  const text = (await res.text()).replace(/&#39;/g, "'");   // the page escapes ' in text, too
  let data = null;
  try {
    data = JSON.parse(text);
  } catch (e) {
    data = null;
  }
  return { status: res.status, headers: res.headers, text, data };
}

const SID = 'A1B2-C3D4-E5F6-0789';
const SID2 = '0000-1111-2222-3333';
const today = new Date().toISOString().slice(0, 10);

function b64urlDecode(s) {
  return Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}
function parseKey(key) {
  const m = /^USO1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/.exec(key || '');
  if (!m) return null;
  return { head: `USO1.${m[1]}`, payloadText: b64urlDecode(m[1]).toString('utf8'), sig: b64urlDecode(m[2]), p: m[1], s: m[2] };
}
function nodeVerifies(key, publicPem = PUBLIC) {
  const k = parseKey(key);
  return Boolean(k) && nodeVerify('sha256', Buffer.from(k.head, 'ascii'), createPublicKey(publicPem), k.sig);   // DER signature
}

/* ───────── the office's check, in PHP — as src/supporter.php does it ───────── */

const PHP_CHECK = String.raw`
$key = $argv[1]; $pub = file_get_contents($argv[2]); $want = $argv[3];
$d = function (string $t): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $t) || strlen($t) % 4 === 1) return null;
    $b = base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4), true);
    return $b !== false && rtrim(strtr(base64_encode($b), '+/', '-_'), '=') === $t ? $b : null;
};
$out = function (array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit(0); };
if (strlen($key) > 1000 || !preg_match('/^USO1\.([A-Za-z0-9_-]{16,800})\.([A-Za-z0-9_-]{8,96})$/D', $key, $m)) $out(['state' => 'invalid', 'why' => 'format']);
$payload = $d($m[1]); $sig = $d($m[2]);
if ($payload === null || $sig === null || strlen($sig) > 72) $out(['state' => 'invalid', 'why' => 'b64']);
$public = openssl_pkey_get_public($pub);
$details = openssl_pkey_get_details($public);
if (($details['ec']['curve_name'] ?? '') !== 'prime256v1') $out(['state' => 'invalid', 'why' => 'curve']);
if (openssl_verify('USO1.' . $m[1], $sig, $public, OPENSSL_ALGO_SHA256) !== 1) $out(['state' => 'invalid', 'why' => 'signature']);
$data = json_decode($payload, true, 2);
if (!is_array($data) || array_keys($data) !== ['v', 'id', 'name', 'date']) $out(['state' => 'invalid', 'why' => 'shape']);
$name = $data['name'];
$nameOk = is_string($name) && $name !== '' && $name === trim($name) && mb_strlen($name) <= 60 && preg_match('/^[^\p{C}\p{Zl}\p{Zp}]+$/uD', $name) === 1;
$dateOk = is_string($data['date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data['date'], $dd) && checkdate((int) $dd[2], (int) $dd[3], (int) $dd[1]);
if ($data['v'] !== 1 || !is_string($data['id']) || !preg_match('/^[0-9A-F]{4}(?:-[0-9A-F]{4}){3}$/D', $data['id']) || !$nameOk || !$dateOk) $out(['state' => 'invalid', 'why' => 'fields']);
$state = hash_equals($want, $data['id']) ? 'valid' : 'other';
if (isset($argv[4]) && $argv[4] !== '') {
    require $argv[4];
    putenv('OFFICE_SUPPORTER_PUBKEY=' . $argv[2]);
    $office = officeSupporterCheck($key, $want);
    $out(['state' => $state, 'name' => $name, 'date' => $data['date'], 'office' => $office['state']]);
}
$out(['state' => $state, 'name' => $name, 'date' => $data['date']]);
`;

let php = null;
try {
  execFileSync('php', ['-v'], { stdio: 'ignore' });
  php = 'php';
} catch (e) {
  console.log('note: no php on PATH — the PHP checks are skipped');
}
function phpCheck(key, want) {
  if (!php) return null;
  const out = execFileSync(php, ['-r', PHP_CHECK, '--', key, pubFile, want, process.env.USO_SUPPORTER_PHP || ''], { encoding: 'utf8' });
  return JSON.parse(out);
}

/* ═════════════════════════ the tests ═════════════════════════ */

// ── the page ──
{
  const r = await call('GET', `/?id=${SID}&lang=de`);
  check('page: 200', r.status === 200, r.status);
  check('page: html', /^text\/html/.test(r.headers.get('content-type')));
  const csp = r.headers.get('content-security-policy') || '';
  const nonce = (/'nonce-([A-Za-z0-9_-]+)'/.exec(csp) || [])[1];
  check('page: CSP with a nonce', Boolean(nonce), csp);
  check('page: CSP script-src only nonce + PayPal', /script-src 'nonce-[^']+' https:\/\/\*\.paypal\.com https:\/\/\*\.paypalobjects\.com;/.test(csp), csp);
  check('page: no unsafe-inline/eval', !/unsafe-(inline|eval)/.test(csp));
  check('page: frame-ancestors none', /frame-ancestors 'none'/.test(csp));
  check('page: default-src none', /default-src 'none'/.test(csp));
  check('page: the script carries the nonce', r.text.includes(`<script nonce="${nonce}">`));
  check('page: the style carries the nonce', r.text.includes(`<style nonce="${nonce}">`));
  check('page: no-store', r.headers.get('cache-control') === 'no-store');
  check('page: nosniff', r.headers.get('x-content-type-options') === 'nosniff');
  check('page: German', r.text.includes('Trinkgeldkasse') && r.text.includes('<html lang="de">'));
  check('page: shows the server ID', r.text.includes(`<code class="id">${SID}</code>`));
  check('page: name field', r.text.includes('id="name"'));
  check('page: the shelter sentence', r.text.includes('Tierheime, die finanzielle Unterstützung dringend brauchen'));
  check('page: helmi1987', r.text.includes('https://github.com/helmi1987'));
  check('page: PayPal card', r.text.includes('id="paypal"'));
  check('page: no other ways when not set', !r.text.includes('id="other"'));
  const script = (/<script nonce="[^"]+">([\s\S]*?)<\/script>/.exec(r.text) || [])[1] || '';
  let syntax = true;
  try {
    new Function(script);   // syntax only, not run
  } catch (e) {
    syntax = String(e);
  }
  check('page: its script parses', syntax === true, syntax);
  const cfg = JSON.parse((/<script type="application\/json" id="cfg">([\s\S]*?)<\/script>/.exec(r.text) || [])[1] || 'null');
  same('page: config', ['test-client-id-0123456789', SID, 'de', 'de_DE'], cfg && [cfg.clientId, cfg.id, cfg.lang, cfg.locale]);
  check('page: config carries no secret', !r.text.includes('test-secret') && !r.text.includes('PRIVATE KEY'));
}
{
  const r = await call('GET', '/', { headers: { 'accept-language': 'fr-CH,fr;q=0.9,en;q=0.5' } });
  check('page: Accept-Language → French', r.text.includes('<html lang="fr">') && r.text.includes('Tirelire'));
  check('page: fr-CH → CHF first', r.text.includes('<option value="CHF" selected>'));
  check('page: without an ID it says so', r.text.includes('ouvre cette page depuis la tirelire'));
  check('page: without an ID no name field', !r.text.includes('id="name"'));
  check('page: without an ID still PayPal', r.text.includes('id="paypal"'));
}
{
  const r = await call('GET', '/?lang=xx', { headers: { 'accept-language': 'nl-NL,pt;q=0.8' } });
  check('page: unknown languages → English', r.text.includes('<html lang="en">') && r.text.includes('<option value="USD" selected>'));
  const evil = await call('GET', `/?id=${encodeURIComponent('"><script>alert(1)</script>')}&lang=${encodeURIComponent('<b>')}`);
  check('page: a bad ID is refused, not reflected', evil.text.includes("server ID in this link isn't valid") && !evil.text.includes('<script>alert'));
  const lower = await call('GET', `/?id=${SID.toLowerCase()}&lang=it`);
  check('page: a lower-case ID is taken as upper-case', lower.text.includes(`<code class="id">${SID}</code>`) && lower.text.includes('Salvadanaio'));
  for (const lang of ['en', 'de', 'it', 'fr', 'es']) {
    const p = await call('GET', `/?id=${SID}&lang=${lang}`);
    check(`page: ${lang} renders`, p.status === 200 && p.text.includes(`<html lang="${lang}">`) && !/\{[a-z_]+\}/.test(p.text.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '')));
  }
  const head = await call('HEAD', '/');
  check('page: HEAD', head.status === 200 && head.text === '');
  const post = await call('POST', '/', { body: {} });
  check('page: POST → 405', post.status === 405 && post.headers.get('allow') === 'GET, HEAD');
  const missing = await call('GET', '/nope');
  check('404 elsewhere', missing.status === 404 && missing.data && missing.data.error === 'not_found');
}

// ── health ──
{
  const r = await call('GET', '/api/health');
  same('health: ok', [200, true, 'sandbox', []], [r.status, r.data.ok, r.data.env, r.data.problems]);
  same('health: methods', { paypal: true, bank: false, lightning: false, onchain: false }, r.data.methods);
  const bad = await call('GET', '/api/health', { env: { ...baseEnv, SUPPORTER_KEY: OTHER_PRIVATE, PAYPAL_ENV: 'prod' } });
  same('health: names what is wrong, nothing else', [503, false, ['PAYPAL_ENV', 'SUPPORTER_KEY']], [bad.status, bad.data.ok, bad.data.problems]);
  check('health: never a value', !bad.text.includes('test-secret') && !bad.text.includes('BEGIN'));
}

// ── order → capture → key ──
let firstKey = null;
let firstOrder = null;
{
  const callsBefore = pp.calls.length;
  const r = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR', name: '  Ada Lovelace ', lang: 'en' } });
  check('order: 200', r.status === 200 && r.data.ok === true, r.text);
  firstOrder = r.data.orderID;
  check('order: an order id', /^5O\d{15}$/.test(firstOrder || ''), firstOrder);
  const created = pp.calls.slice(callsBefore).find((c) => c.path === '/v2/checkout/orders');
  const body = created && JSON.parse(created.body);
  same('order: intent CAPTURE', 'CAPTURE', body && body.intent);
  same('order: amount', { currency_code: 'EUR', value: '5.00' }, body && body.purchase_units[0].amount);
  same('order: custom_id = server ID', SID, body && body.purchase_units[0].custom_id);
  same('order: description', 'Tip for the Unraid Secretary Office', body && body.purchase_units[0].description);
  same('order: no shipping', 'NO_SHIPPING', body && body.application_context.shipping_preference);
  check('order: a PayPal-Request-Id', Boolean(created && created.headers.get('paypal-request-id')));
  const rec = await kv.get(`order:${firstOrder}`, 'json');
  same('order: the KV record', { id: SID, amount: '5.00', currency: 'EUR', name: 'Ada Lovelace' }, rec && { id: rec.id, amount: rec.amount, currency: rec.currency, name: rec.name });
  check('order: the record expires', kv.map.get(`order:${firstOrder}`).opts.expirationTtl === 3 * 86400);

  const c = await call('POST', '/api/capture', { body: { orderID: firstOrder } });
  check('capture: 200 with a key', c.status === 200 && c.data.ok === true && typeof c.data.key === 'string', c.text);
  firstKey = c.data.key;
  same('capture: name and date', ['Ada Lovelace', today, SID], [c.data.name, c.data.date, c.data.id]);
  const capCall = pp.calls.find((x) => x.path === `/v2/checkout/orders/${firstOrder}/capture`);
  same('capture: idempotent request id', `uso-capture-${firstOrder}`, capCall && capCall.headers.get('paypal-request-id'));
  const k = parseKey(firstKey);
  same('key: the payload, byte for byte', `{"v":1,"id":"${SID}","name":"Ada Lovelace","date":"${today}"}`, k && k.payloadText);
  check('key: b64url without padding', k && !/[=+/]/.test(firstKey));
  check('key: DER signature (SEQUENCE of two INTEGERs)', k && k.sig[0] === 0x30 && k.sig[1] === k.sig.length - 2 && k.sig[2] === 0x02 && k.sig.length <= 72, k && k.sig.toString('hex'));
  check('key: Node verifies it (DER)', nodeVerifies(firstKey));
  check("key: not with another key's public key", !nodeVerifies(firstKey, other.publicKey.export({ type: 'spki', format: 'pem' })));
  const ph = phpCheck(firstKey, SID);
  if (ph) {
    same('key: PHP (openssl_verify) says valid', { state: 'valid', name: 'Ada Lovelace', date: today }, { state: ph.state, name: ph.name, date: ph.date });
    if (process.env.USO_SUPPORTER_PHP) same("key: the office's officeSupporterCheck says valid", 'valid', ph.office);
    const ph2 = phpCheck(firstKey, SID2);
    same('key: PHP — for another server', 'other', ph2.state);
    const tampered = firstKey.replace(/\.([A-Za-z0-9_-])/, (m0, ch) => `.${ch === 'A' ? 'B' : 'A'}`);
    same('key: PHP — a changed key is invalid', 'invalid', phpCheck(tampered, SID).state);
  }
  const stored = await kv.get(`key:${SID}`, 'json');
  same('capture: KV keeps {key, date, via}', { key: firstKey, date: today, via: 'paypal' }, stored);
  const rec2 = await kv.get(`order:${firstOrder}`, 'json');
  check('capture: the order is marked done', rec2 && rec2.done === true && rec2.paid === today);
  const everything = kv.dump();
  check('capture: nothing about the payer in KV', ![PAYER_EMAIL, 'Payer-Secret', PAYER_ID, 'Secret Street', 'MERCHANT1'].some((s) => everything.includes(s)), everything);

  // the same capture again: no second PayPal capture, a key again
  const before = pp.calls.length;
  const again = await call('POST', '/api/capture', { body: { orderID: firstOrder } });
  check('capture again: a key again', again.status === 200 && nodeVerifies(again.data.key) && again.data.name === 'Ada Lovelace');
  check('capture again: PayPal not asked', pp.calls.length === before);
}

// ── lost key ──
{
  const r = await call('GET', `/api/key?id=${SID}`);
  same('lost key: found', [200, true, firstKey, today, 'Ada Lovelace', 'paypal'], [r.status, r.data.ok, r.data.key, r.data.date, r.data.name, r.data.via]);
  const lower = await call('GET', `/api/key?id=${SID.toLowerCase()}`);
  same('lost key: lower-case ID', firstKey, lower.data && lower.data.key);
  const none = await call('GET', `/api/key?id=${SID2}`);
  same('lost key: unknown ID → 404', [404, 'not_found'], [none.status, none.data.error]);
  for (const bad of ['', 'A1B2C3D4E5F60789', 'A1B2-C3D4-E5F6-078G', 'A1B2-C3D4-E5F6-0789-0000', '../key', 'A1B2-C3D4-E5F6-0789\n']) {
    const b = await call('GET', `/api/key?id=${encodeURIComponent(bad)}`);
    same(`lost key: bad ID ${JSON.stringify(bad)} → 400`, [400, 'bad_id'], [b.status, b.data && b.data.error]);
  }
  const extra = await call('GET', `/api/key?id=${SID}&x=1`);
  same('lost key: extra parameters → 400', 400, extra.status);
  const post = await call('POST', `/api/key?id=${SID}`, { body: {} });
  same('lost key: POST → 405', 405, post.status);
  const page = await call('GET', `/?id=${SID}&lang=en`);
  check('page with a key: shows it again', page.text.includes('id="have"') && page.text.includes(firstKey) && page.text.includes('«Ada Lovelace»'));
  const page2 = await call('GET', `/?id=${SID2}&lang=en`);
  check('page without a key: no such card', !page2.text.includes('id="have"'));
  // rate limit: the same IP, many lookups
  const ip = nextIp();
  const codes = [];
  for (let i = 0; i < 35; i++) codes.push((await call('GET', `/api/key?id=${SID}`, { ip })).status);
  check('lost key: rate-limited per IP', codes.slice(0, 30).every((s) => s === 200) && codes.slice(30).every((s) => s === 429), codes.join(','));
  const otherIp = await call('GET', `/api/key?id=${SID}`);
  check('lost key: another IP is not', otherIp.status === 200);
  const limitedPage = await call('GET', `/?id=${SID}`, { ip });
  check('page: still there when rate-limited, without the key', limitedPage.status === 200 && !limitedPage.text.includes(firstKey));
}

// ── names, defaults, Unicode ──
async function tip(body, behaviour = {}) {
  const o = await call('POST', '/api/order', { body });
  if (!o.data || !o.data.ok) return { order: o };
  Object.assign(pp.orders.get(o.data.orderID).behaviour, behaviour);
  const c = await call('POST', '/api/capture', { body: { orderID: o.data.orderID } });
  return { order: o, capture: c, orderID: o.data.orderID };
}
{
  for (const [lang, word] of [['en', 'Supporter'], ['de', 'Supporter'], ['it', 'Mecenate'], ['fr', 'Mécène'], ['es', 'Mecenas']]) {
    const t = await tip({ id: SID2, amount: 3, currency: 'CHF', lang });
    same(`default name (${lang})`, word, t.capture && t.capture.data.name);
  }
  const blank = await tip({ id: SID2, amount: '3', currency: 'USD', name: '   ', lang: 'de' });
  same('a blank name → the default', 'Supporter', blank.capture.data.name);
  const fancy = 'Zoë "Q" \\ Müller 🍿 <b>&amp;</b> ½';
  const f = await tip({ id: SID2, amount: '10.5', currency: 'USD', name: fancy });
  same('Unicode name: kept as typed', fancy, f.capture.data.name);
  check('Unicode name: Node verifies', nodeVerifies(f.capture.data.key));
  same('Unicode name: the payload', `{"v":1,"id":"${SID2}","name":${JSON.stringify(fancy)},"date":"${today}"}`, parseKey(f.capture.data.key).payloadText);
  const ph = phpCheck(f.capture.data.key, SID2);
  if (ph) {
    same('Unicode name: PHP valid, same name', ['valid', fancy], [ph.state, ph.name]);
    if (process.env.USO_SUPPORTER_PHP) same('Unicode name: office valid', 'valid', ph.office);
  }
  const sixty = '🍿'.repeat(30) + 'é'.repeat(30);
  const s = await tip({ id: SID2, amount: '1', currency: 'EUR', name: sixty });
  check('60 characters (emoji count once) are fine', s.capture && s.capture.status === 200 && s.capture.data.name === sixty);
  const ps = s.capture && phpCheck(s.capture.data.key, SID2);
  if (ps) same('60 characters: PHP valid', 'valid', ps.state);
  const evil = await tip({ id: SID2, amount: '2', currency: 'EUR', name: '<img src=x onerror=alert(1)>' });
  const page3 = await call('GET', `/?id=${SID2}&lang=en`);
  check('page: markup in a name stays text', evil.capture.status === 200 && page3.text.includes('&lt;img src=x onerror=alert(1)&gt;') && !page3.text.includes('<img src=x'));
}

// ── bad input ──
{
  const n = pp.calls.length;
  const w = kv.writes;
  const cases = [
    ['amount 0.99', { id: SID, amount: '0.99', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount 0', { id: SID, amount: 0, currency: 'EUR' }, 400, 'bad_amount'],
    ['amount 1000.01', { id: SID, amount: '1000.01', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount 5.001', { id: SID, amount: '5.001', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount -5', { id: SID, amount: '-5', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount 1e3', { id: SID, amount: '1e3', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount NaN', { id: SID, amount: 'abc', currency: 'EUR' }, 400, 'bad_amount'],
    ['amount missing', { id: SID, currency: 'EUR' }, 400, 'bad_amount'],
    ['amount as object', { id: SID, amount: { value: 5 }, currency: 'EUR' }, 400, 'bad_amount'],
    ['currency GBP', { id: SID, amount: '5', currency: 'GBP' }, 400, 'bad_currency'],
    ['currency eur', { id: SID, amount: '5', currency: 'eur' }, 400, 'bad_currency'],
    ['id with letters beyond F', { id: 'G1B2-C3D4-E5F6-0789', amount: '5', currency: 'EUR' }, 400, 'bad_id'],
    ['id as number', { id: 1234, amount: '5', currency: 'EUR' }, 400, 'bad_id'],
    ['name 61 characters', { id: SID, amount: '5', currency: 'EUR', name: 'x'.repeat(61) }, 400, 'bad_name'],
    ['name with a control character', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\u0007' }, 400, 'bad_name'],
    ['name with a line break', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\nLovelace' }, 400, 'bad_name'],
    ['name with U+2028 inside', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\u2028Lovelace' }, 400, 'bad_name'],
    ['name with a tab inside', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\tLovelace' }, 400, 'bad_name'],
    ['name with a bidi override', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\u202eecalevol' }, 400, 'bad_name'],
    ['name with a zero-width joiner only', { id: SID, amount: '5', currency: 'EUR', name: '\u200d' }, 400, 'bad_name'],
    ['name with a lone surrogate', { id: SID, amount: '5', currency: 'EUR', name: 'Ada\ud800' }, 400, 'bad_name'],
    ['name as array', { id: SID, amount: '5', currency: 'EUR', name: ['Ada'] }, 400, 'bad_name'],
    ['an unknown field', { id: SID, amount: '5', currency: 'EUR', email: 'a@b.c' }, 400, 'bad_request'],
  ];
  for (const [what, body, status, code] of cases) {
    const r = await call('POST', '/api/order', { body });
    same(`order refused: ${what}`, [status, code], [r.status, r.data && r.data.error]);
  }
  const notObject = await call('POST', '/api/order', { body: '[1,2]', raw: true });
  same('order refused: not an object', [400, 'bad_request'], [notObject.status, notObject.data.error]);
  const notJson = await call('POST', '/api/order', { body: 'amount=5', raw: true });
  same('order refused: not JSON', 400, notJson.status);
  const form = await call('POST', '/api/order', { body: 'amount=5', raw: true, headers: { 'content-type': 'application/x-www-form-urlencoded' } });
  same('order refused: a form post → 415', 415, form.status);
  const big = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR', name: 'x'.repeat(3000) } });
  same('order refused: a body over 2 KB → 413', 413, big.status);
  const badUtf8 = await worker.fetch(new Request(`${ORIGIN}/api/order`, {
    method: 'POST', headers: { 'content-type': 'application/json', origin: ORIGIN }, body: new Uint8Array([0x7b, 0xff, 0x7d]),
  }), baseEnv, ctx);
  same('order refused: invalid UTF-8', 400, badUtf8.status);
  const cross = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, headers: { origin: 'https://evil.example' } });
  same('order refused: another origin → 403', [403, 'forbidden'], [cross.status, cross.data.error]);
  const site = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, headers: { 'sec-fetch-site': 'cross-site' } });
  same('order refused: Sec-Fetch-Site cross-site → 403', 403, site.status);
  const get = await call('GET', '/api/order');
  same('order: GET → 405', [405, 'POST'], [get.status, get.headers.get('allow')]);
  check('bad input: PayPal never asked', pp.calls.length === n, pp.calls.slice(n).map((c) => c.path));
  check('bad input: nothing written to KV', kv.writes === w);
  const ok = await call('POST', '/api/order', { body: { id: SID, amount: '1000', currency: 'CHF', name: 'Max' } });
  check('order: 1000 is still fine', ok.status === 200 && ok.data.ok);
  const comma = await call('POST', '/api/order', { body: { id: SID, amount: '7,50', currency: 'EUR' } });
  const rec = comma.data && (await kv.get(`order:${comma.data.orderID}`, 'json'));
  same('order: "7,50" → 7.50', '7.50', rec && rec.amount);
}

// ── captures that must not give a key ──
{
  const keysBefore = (await kv.get(`key:${SID2}`, 'json')).key;
  // an order this worker never created (it exists at PayPal, but not here)
  const n = pp.calls.length;
  pp.n++;
  const foreign = `5O${String(pp.n).padStart(15, '0')}`;
  pp.orders.set(foreign, { id: foreign, request: { purchase_units: [{ reference_id: 'x', amount: { currency_code: 'EUR', value: '1.00' }, custom_id: SID2 }] }, captured: false, behaviour: {}, captureRequestIds: new Set() });
  const r = await call('POST', '/api/capture', { body: { orderID: foreign } });
  same('foreign order: 404 unknown_order', [404, 'unknown_order'], [r.status, r.data.error]);
  check('foreign order: PayPal not asked', pp.calls.length === n);
  check('foreign order: not captured', pp.orders.get(foreign).captured === false);
  for (const bad of ['', 'x', '../../v1/oauth2/token', 'A'.repeat(65), 123, null]) {
    const b = await call('POST', '/api/capture', { body: { orderID: bad } });
    same(`capture refused: orderID ${JSON.stringify(bad)}`, [400, 'bad_order'], [b.status, b.data.error]);
  }
  const extra = await call('POST', '/api/capture', { body: { orderID: firstOrder, key: 'x' } });
  same('capture refused: extra fields', 400, extra.status);

  const declined = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { captureStatus: 'DECLINED', orderStatus: 'COMPLETED' });
  same('capture DECLINED: 409 not_completed, no key', [409, 'not_completed', undefined], [declined.capture.status, declined.capture.data.error, declined.capture.data.key]);
  const voided = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { orderStatus: 'VOIDED', captureStatus: 'COMPLETED' });
  same('order VOIDED: 409', 409, voided.capture.status);
  const notApproved = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { notApproved: true });
  same('not approved: 409 not_completed', [409, 'not_completed'], [notApproved.capture.status, notApproved.capture.data.error]);
  const instrument = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { declined: true });
  same('card declined: 402 declined (the buttons restart)', [402, 'declined'], [instrument.capture.status, instrument.capture.data.error]);
  const amount = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { amount: { currency_code: 'EUR', value: '0.01' } });
  same('a different amount: 409 mismatch', [409, 'mismatch'], [amount.capture.status, amount.capture.data.error]);
  const currency = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { amount: { currency_code: 'USD', value: '4.00' } });
  same('a different currency: 409 mismatch', 409, currency.capture.status);
  const custom = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { customId: SID });
  same('another custom_id: 409 mismatch', [409, 'mismatch'], [custom.capture.status, custom.capture.data.error]);
  const noCustom = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Nope' }, { omitCustom: true });
  same('no custom_id anywhere: 409 mismatch', 409, noCustom.capture.status);
  same('no key for any of them', keysBefore, (await kv.get(`key:${SID2}`, 'json')).key);
  const everything = kv.dump();
  check('no "Nope" key was kept', !Object.values(Object.fromEntries(kv.map)).some((v) => v.value.includes('"via"') && parseKey(JSON.parse(v.value).key)?.payloadText.includes('Nope')));
  check('still nothing about payers in KV', ![PAYER_EMAIL, 'Payer-Secret', PAYER_ID].some((s) => everything.includes(s)));

  const onlyGet = await tip({ id: SID2, amount: '4', currency: 'EUR', name: 'Grace' }, { omitCustomOnCapture: true });
  check('custom_id missing in the capture answer: asked for the order, then a key', onlyGet.capture.status === 200 && onlyGet.capture.data.name === 'Grace' && nodeVerifies(onlyGet.capture.data.key));
}

// ── pending, then completed ──
{
  const t = await tip({ id: SID, amount: '6', currency: 'USD', name: 'Patient' }, { captureStatus: 'PENDING' });
  same('pending: 202 pending, no key', [202, 'pending', undefined], [t.capture.status, t.capture.data.error, t.capture.data.key]);
  check('pending: kept in the record', (await kv.get(`order:${t.orderID}`, 'json')).pending === true);
  same('pending: no new key yet', firstKey, (await kv.get(`key:${SID}`, 'json')).key);
  pp.orders.get(t.orderID).behaviour.captureStatus = 'COMPLETED';
  const n = pp.calls.length;
  const again = await call('POST', '/api/capture', { body: { orderID: t.orderID } });
  check('pending → completed: the key', again.status === 200 && again.data.name === 'Patient' && nodeVerifies(again.data.key));
  check('pending → completed: looked up, not captured again', pp.calls.slice(n).every((c) => c.method === 'GET'));
  same('the newest key wins', again.data.key, (await kv.get(`key:${SID}`, 'json')).key);
  firstKey = again.data.key;
}

// ── a tip without a server ID ──
{
  const o = await call('POST', '/api/order', { body: { amount: '5', currency: 'EUR', lang: 'it' } });
  check('no ID: an order', o.status === 200);
  const created = pp.orders.get(o.data.orderID).request.purchase_units[0];
  check('no ID: no custom_id', !('custom_id' in created));
  const rec = await kv.get(`order:${o.data.orderID}`, 'json');
  same('no ID: no name kept', [null, null], [rec.id, rec.name]);
  const c = await call('POST', '/api/capture', { body: { orderID: o.data.orderID } });
  same('no ID: thanks, no key', [200, true, null], [c.status, c.data.ok, c.data.key]);
  const named = await call('POST', '/api/order', { body: { amount: '5', currency: 'EUR', name: 'Dropped' } });
  const rec2 = await kv.get(`order:${named.data.orderID}`, 'json');
  same('no ID: a name is dropped', null, rec2.name);
  const badName = await call('POST', '/api/order', { body: { amount: '5', currency: 'EUR', name: 'x'.repeat(70) } });
  same('no ID: a bad name is still refused', 400, badName.status);
}

// ── PayPal hiccups ──
{
  pp.unauthorizedOnce = true;
  const tokens = pp.tokens;
  const t = await tip({ id: SID, amount: '2', currency: 'EUR', name: 'Retry' });
  check('an expired token: fetched anew, then it works', t.order.status === 200 && pp.tokens === tokens + 1);
  check('tokens are reused', pp.tokens <= 3, pp.tokens);
  pp.down = true;
  const down = await call('POST', '/api/order', { body: { id: SID, amount: '2', currency: 'EUR' } });
  same('PayPal unreachable: 502 paypal', [502, 'paypal'], [down.status, down.data.error]);
  pp.down = false;
  // capture answered with an error the first time: the page's «Check again» retries
  const o = await call('POST', '/api/order', { body: { id: SID, amount: '2', currency: 'EUR', name: 'Again' } });
  pp.down = true;
  const c1 = await call('POST', '/api/capture', { body: { orderID: o.data.orderID } });
  pp.down = false;
  const c2 = await call('POST', '/api/capture', { body: { orderID: o.data.orderID } });
  check('capture after a failed try: the key', c1.status === 502 && c2.status === 200 && c2.data.name === 'Again');
  // KV failing at the end: the buyer still gets the key
  const o2 = await call('POST', '/api/order', { body: { id: SID2, amount: '2', currency: 'EUR', name: 'KV down' } });
  kv.failPut = true;
  const c3 = await call('POST', '/api/capture', { body: { orderID: o2.data.orderID } });
  kv.failPut = false;
  check('KV failing after payment: the key anyway', c3.status === 200 && c3.data.name === 'KV down' && nodeVerifies(c3.data.key));
}

// ── settings that are wrong: refused before PayPal ──
{
  const n = pp.calls.length;
  const noKey = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, env: { ...baseEnv, SUPPORTER_KEY: undefined } });
  same('no SUPPORTER_KEY: 503 server_config', [503, 'server_config'], [noKey.status, noKey.data.error]);
  const wrongKey = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, env: { ...baseEnv, SUPPORTER_KEY: OTHER_PRIVATE } });
  same("a key that doesn't match the office's public key: 503", 503, wrongKey.status);
  const realPub = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, env: { ...baseEnv, SUPPORTER_PUBLIC_KEY: undefined } });
  same("the throw-away key against the office's real public key: 503", 503, realPub.status);
  const noKv = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, env: { ...baseEnv, SUPPORT_KV: undefined } });
  same('no KV: 503', 503, noKv.status);
  const noEnv = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, env: { ...baseEnv, PAYPAL_ENV: '' } });
  same('no PAYPAL_ENV: 503', 503, noEnv.status);
  check('wrong settings: PayPal never asked', pp.calls.length === n);
  const sec1env = { ...baseEnv, SUPPORTER_KEY: PRIVATE_SEC1 };
  const o = await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR', name: 'SEC1' }, env: sec1env });
  const c = await call('POST', '/api/capture', { body: { orderID: o.data.orderID }, env: sec1env });
  check('a SEC1 key (BEGIN EC PRIVATE KEY) works too', c.status === 200 && nodeVerifies(c.data.key));
  const spaced = { ...baseEnv, SUPPORTER_KEY: `\n  ${PRIVATE.replace(/\n/g, '\r\n')}  \n`, PAYPAL_CLIENT_ID: ' test-client-id-0123456789\n' };
  const s = await call('GET', '/api/health', { env: spaced });
  same('pasted settings with spaces and CRLF still work', true, s.data.ok);
  const noPaypal = await call('GET', `/?id=${SID}`, { env: { ...baseEnv, PAYPAL_CLIENT_ID: '' } });
  check('page without PayPal and nothing else: says it is not set up', noPaypal.text.includes("isn't set up yet") && !noPaypal.text.includes('id="paypal"'));
}

// ── other ways to give: bank transfer, Bitcoin, «I've sent it» ──
const LN = 'tips@example.com';
const ONCHAIN = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';   // BIP-173 test vector
const waysEnv = {
  ...baseEnv,
  BANK_IBAN: 'CH93 0076 2011 6238 5295 7', BANK_HOLDER: 'Benjamin Muster', BANK_BIC: 'POFICHBEXXX', BANK_NAME: 'PostFinance, Bern',
  BTC_LIGHTNING: LN, BTC_ADDRESS: ONCHAIN,
};
{
  const h = await call('GET', '/api/health', { env: waysEnv });
  same('ways: health', [true, { paypal: true, bank: true, lightning: true, onchain: true }], [h.data.ok, h.data.methods]);
  const badWays = { ...baseEnv, BANK_IBAN: 'CH93 0076 2011 6238 5295 8', BANK_HOLDER: 'X', BTC_LIGHTNING: 'not an address', BTC_ADDRESS: 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5' };
  const hb = await call('GET', '/api/health', { env: badWays });
  same('ways: wrong settings are named', ['BANK_IBAN', 'BTC_LIGHTNING', 'BTC_ADDRESS'], hb.data.problems);
  const pb = await call('GET', `/?id=${SID}`, { env: badWays });
  check('ways: wrong settings are not shown', !pb.text.includes('id="other"'));
  const taproot = await call('GET', '/api/health', { env: { ...baseEnv, BTC_ADDRESS: 'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0' } });
  same('ways: a bech32m (taproot) address', true, taproot.data.methods.onchain);
  const legacy = await call('GET', '/api/health', { env: { ...baseEnv, BTC_ADDRESS: '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2' } });
  same('ways: a legacy address', true, legacy.data.methods.onchain);
  const ibanOnly = await call('GET', `/?id=${SID}&lang=de`, { env: { ...baseEnv, BANK_IBAN: 'CH9300762011623852957', BANK_HOLDER: 'Benjamin Muster' } });
  check('ways: only a bank account', ibanOnly.text.includes('id="bank"') && !ibanOnly.text.includes('id="bitcoin"') && !ibanOnly.text.includes('BIC'));

  const p = await call('GET', `/?id=${SID}&lang=en`, { env: waysEnv });
  check('ways: the card', p.text.includes('id="other"') && p.text.includes('Other ways to give'));
  check('ways: IBAN grouped, copied without spaces', p.text.includes('CH93 0076 2011 6238 5295 7') && p.text.includes('data-copy-text="CH9300762011623852957"'));
  check('ways: holder, BIC, bank', p.text.includes('Benjamin Muster') && p.text.includes('POFICHBEXXX') && p.text.includes('PostFinance, Bern'));
  check('ways: the fee note', p.text.includes('From outside Switzerland and the SEPA area'));
  check('ways: Lightning and on-chain with QR codes', p.text.includes(LN) && p.text.includes(ONCHAIN) && (p.text.match(/<svg class="qr"/g) || []).length === 2);
  check('ways: Lightning suits small amounts', p.text.includes('Lightning suits small amounts'));
  check('ways: the honour buttons', p.text.includes('data-honour="bank"') && p.text.includes('data-honour="bitcoin"') && p.text.includes("I've sent it — show my key"));
  check('ways: says plainly it works on trust', p.text.includes('this works on trust') && p.text.includes('It unlocks nothing anyway'));
  const csp = p.headers.get('content-security-policy');
  check('ways: the CSP is unchanged (inline SVG needs nothing)', !/unsafe/.test(csp));
  const noId = await call('GET', '/?lang=en', { env: waysEnv });
  check('ways: without an ID no honour button', noId.text.includes('id="bank"') && !noId.text.includes('data-honour="'));
  for (const lang of ['de', 'it', 'fr', 'es']) {
    const q = await call('GET', `/?id=${SID}&lang=${lang}`, { env: waysEnv });
    check(`ways: ${lang} renders`, q.status === 200 && q.text.includes('data-honour="bank"') && !/\{[a-z_]+\}/.test(q.text.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '')));
  }
  const noPaypal = await call('GET', `/?id=${SID}`, { env: { ...waysEnv, PAYPAL_CLIENT_ID: '' } });
  check('ways: without PayPal only the other ways', !noPaypal.text.includes('id="paypal"') && noPaypal.text.includes('id="other"') && !noPaypal.text.includes("isn't set up yet"));

  if (process.env.QR_OUT) {
    mkdirSync(process.env.QR_OUT, { recursive: true });
    const svgs = p.text.match(/<svg class="qr"[\s\S]*?<\/svg>/g) || [];
    svgs.forEach((svg, i) => writeFileSync(join(process.env.QR_OUT, `qr-${i}.svg`), svg));
    writeFileSync(join(process.env.QR_OUT, 'expected.json'), JSON.stringify([`lightning:${lnurlOf(LN)}`, `bitcoin:${ONCHAIN}`]));
  }

  // «I've sent it — show my key»
  const w0 = kv.writes;
  const r = await call('POST', '/api/honour', { body: { id: SID2, via: 'bank', name: 'Honest Abe', lang: 'en' }, env: waysEnv });
  check('honour: a key at once', r.status === 200 && r.data.ok && r.data.name === 'Honest Abe' && nodeVerifies(r.data.key), r.text);
  const ph = phpCheck(r.data.key, SID2);
  if (ph) same('honour: PHP valid', 'valid', ph.state);
  same('honour: KV keeps {key, date, via}', { key: r.data.key, date: today, via: 'bank' }, await kv.get(`key:${SID2}`, 'json'));
  const again = await call('POST', '/api/honour', { body: { id: SID2, via: 'bank', name: 'Honest Abe', lang: 'en' }, env: waysEnv });
  check('honour: the same again → the kept key, no new write', again.data.key === r.data.key && kv.writes === w0 + 1);
  const btc = await call('POST', '/api/honour', { body: { id: SID2, via: 'bitcoin', lang: 'fr' }, env: waysEnv });
  check('honour: Bitcoin, default name', btc.status === 200 && btc.data.name === 'Mécène');
  same('honour: via bitcoin kept', 'bitcoin', (await kv.get(`key:${SID2}`, 'json')).via);
  const lost = await call('GET', `/api/key?id=${SID2}`, { env: waysEnv });
  same('honour: lost key finds it', [btc.data.key, 'bitcoin'], [lost.data.key, lost.data.via]);
  const refused = [
    ['no ID', { via: 'bank' }, 400, 'bad_id'],
    ['a bad ID', { id: 'nope', via: 'bank' }, 400, 'bad_id'],
    ['via paypal', { id: SID2, via: 'paypal' }, 400, 'bad_via'],
    ['via nothing', { id: SID2 }, 400, 'bad_via'],
    ['via unknown', { id: SID2, via: 'cash' }, 400, 'bad_via'],
    ['a bad name', { id: SID2, via: 'bank', name: 'x\u0000y' }, 400, 'bad_name'],
    ['an unknown field', { id: SID2, via: 'bank', amount: 5 }, 400, 'bad_request'],
  ];
  for (const [what, body, status, code] of refused) {
    const x = await call('POST', '/api/honour', { body, env: waysEnv });
    same(`honour refused: ${what}`, [status, code], [x.status, x.data && x.data.error]);
  }
  const notOffered = await call('POST', '/api/honour', { body: { id: SID2, via: 'bank' }, env: baseEnv });
  same('honour refused: bank not offered', [400, 'bad_via'], [notOffered.status, notOffered.data.error]);
  const onlyBank = await call('POST', '/api/honour', { body: { id: SID2, via: 'bitcoin' }, env: { ...baseEnv, BANK_IBAN: 'CH9300762011623852957', BANK_HOLDER: 'B' } });
  same('honour refused: Bitcoin not offered', 400, onlyBank.status);
  const cross = await call('POST', '/api/honour', { body: { id: SID2, via: 'bank' }, env: waysEnv, headers: { origin: 'https://evil.example' } });
  same('honour refused: another origin', 403, cross.status);
  const wrongKey = await call('POST', '/api/honour', { body: { id: SID2, via: 'bank' }, env: { ...waysEnv, SUPPORTER_KEY: OTHER_PRIVATE } });
  same('honour refused: a wrong signing key', 503, wrongKey.status);
  check('honour: no PayPal call ever', !pp.calls.some((c) => c.body && String(c.body).includes('Honest')));

  // rate limits: per IP, per server ID
  const ip = nextIp();
  const perIp = [];
  for (let i = 0; i < 8; i++) {
    const id = `ABCD-0000-0000-000${i}`;
    perIp.push((await call('POST', '/api/honour', { body: { id, via: 'bank', name: `n${i}` }, env: waysEnv, ip })).status);
  }
  same('honour: 6 per IP and hour', [200, 200, 200, 200, 200, 200, 429, 429], perIp);
  const perId = [];
  for (let i = 0; i < 6; i++) {
    perId.push((await call('POST', '/api/honour', { body: { id: 'FEED-BEEF-0000-0001', via: 'bank', name: `name ${i}` }, env: waysEnv })).status);
  }
  same('honour: 4 per server ID and hour, whatever the IP', [200, 200, 200, 200, 429, 429], perId);
  const kvKeys = [...kv.map.keys()];
  check('KV holds only order:… and key:… records', kvKeys.every((k) => /^order:5O\d{15}$/.test(k) || /^key:[0-9A-F]{4}(?:-[0-9A-F]{4}){3}$/.test(k)), kvKeys);
  const values = [...kv.map.entries()].filter(([k]) => k.startsWith('key:')).map(([, v]) => Object.keys(JSON.parse(v.value)).join(','));
  check('key records hold exactly key, date, via', values.every((v) => v === 'key,date,via'), values);
}

// ── the rate limiting binding and the Cache API, when there ──
{
  let asked = 0;
  const env = { ...baseEnv, RATE_LIMITER: { limit: async () => { asked++; return { success: asked <= 2 }; } } };
  const codes = [];
  for (let i = 0; i < 4; i++) codes.push((await call('GET', `/api/key?id=${SID}`, { env })).status);
  same('the rate limiting binding can refuse', [200, 200, 429, 429], codes);
  const store = new Map();
  globalThis.caches = {
    default: {
      async match(req) { const v = store.get(req.url); return v === undefined ? undefined : new Response(v); },
      async put(req, res) { store.set(req.url, await res.text()); },
    },
  };
  const ip = nextIp();
  const list = [];
  for (let i = 0; i < 13; i++) list.push((await call('POST', '/api/order', { body: { id: SID, amount: '5', currency: 'EUR' }, ip })).status);
  await Promise.all(waits);
  check('the Cache API counts too', store.size > 0 && [...store.keys()].every((k) => k.startsWith(`${ORIGIN}/__rate/`)), [...store.keys()]);
  check('order: rate-limited per IP (12 per 10 min)', list.slice(0, 12).every((s) => s === 200) && list[12] === 429, list.join(','));
  check('the counters never hold an address', ![...store.keys()].some((k) => k.includes('2001') || k.includes('db8')));
  delete globalThis.caches;
}

// ── the bech32 encoding behind the Lightning QR code (LUD-01's example) ──
function lnurlOf(address) {
  const [user, domain] = address.split('@');
  return bech32Ref('lnurl', Buffer.from(`https://${domain}/.well-known/lnurlp/${user}`)).toUpperCase();
}
function bech32Ref(hrp, data) {   // an independent reference implementation (BIP-173), for comparison
  const C = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  const words = [];
  let value = 0;
  let bits = 0;
  for (const byte of data) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      words.push((value >> (bits - 5)) & 31);
      bits -= 5;
    }
    value &= (1 << bits) - 1;
  }
  if (bits) words.push((value << (5 - bits)) & 31);
  const polymod = (values) => {
    const G = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    let chk = 1;
    for (const v of values) {
      const b = chk >> 25;
      chk = ((chk & 0x1ffffff) << 5) ^ v;
      for (let i = 0; i < 5; i++) if ((b >> i) & 1) chk ^= G[i];
    }
    return chk;
  };
  const exp = [...hrp].map((c) => c.charCodeAt(0) >> 5).concat([0], [...hrp].map((c) => c.charCodeAt(0) & 31));
  const mod = polymod(exp.concat(words, [0, 0, 0, 0, 0, 0])) ^ 1;
  const sum = [];
  for (let i = 0; i < 6; i++) sum.push((mod >> (5 * (5 - i))) & 31);
  return `${hrp}1${words.concat(sum).map((w) => C[w]).join('')}`;
}
same('bech32 reference: LUD-01 example',
  'LNURL1DP68GURN8GHJ7UM9WFMXJCM99E3K7MF0V9CXJ0M385EKVCENXC6R2C35XVUKXEFCV5MKVV34X5EKZD3EV56NYD3HXQURZEPEXEJXXEPNXSCRVWFNV9NXZCN9XQ6XYEFHVGCXXCMYXYMNSERXFQ5FNS',
  bech32Ref('lnurl', Buffer.from('https://service.com/api?q=3fc3645b439ce8e7f2553a69e5267081d96dcd340693afabe04be7b0ccd178df')).toUpperCase());

// ── the log ──
{
  const all = logged.join('\n');
  check('the worker logged something (PayPal down, KV failing)', logged.length > 0);
  check('the log holds no names, no payer, no bodies', !['Ada', 'Lovelace', 'Again', 'KV down', 'Honest', PAYER_EMAIL, PAYER_ID, 'Payer-Secret', '"amount"', 'currency', 'test-secret', 'A21-test-token'].some((x) => all.includes(x)), all);
}

await Promise.all(waits);
rmSync(tmp, { recursive: true, force: true });
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
