'use strict';

/* Task.txt gap coverage for the backoffice POS frontend libs (23/24/25 + §3).
 * Only assertions missing from pos-phase2/5/6-11/lifecycle live here; nothing
 * below duplicates an existing test. Seeded PRNG loops stand in for
 * fast-check (not installed): deterministic, no new dependencies.
 */
require('./helpers/register-ts.cjs');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const money = require(path.join(POS, 'lib/money.ts'));
const { normAr } = require(path.join(POS, 'lib/arabic.ts'));
const { normalizeError, PosApiError, isCancel } = require(path.join(POS, 'lib/pos-error.ts'));
const { createPendingSaleManager } = require(path.join(POS, 'lib/pending-sale.ts'));
const { createScanDetector } = require(path.join(POS, 'lib/barcode-scanner.ts'));
const { createTabCoordinator } = require(path.join(POS, 'lib/tab-coordinator.ts'));
const { pushReceipt, getReceiptArchive, clearReceiptArchive } = require(path.join(POS, 'lib/receipt-archive.ts'));
const { PosApiService } = require(path.join(POS, 'services/pos-api.core.ts'));
const { createApiClient } = require(path.join(POS, '../../../../../packages/api-client/src/axios-instance.ts'));

function mulberry32(seed) {
  let s = seed >>> 0;
  return () => {
    s = (s + 0x6d2b79f5) >>> 0;
    let t = s;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function memStorage() {
  const m = new Map();
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => void m.set(k, String(v)),
    removeItem: (k) => void m.delete(k),
  };
}

function fakeHttp(routes) {
  const calls = [];
  const h = {
    calls,
    async request(method, url, a, b) {
      calls.push({ method, url, a, b });
      const r = routes[`${method} ${url}`];
      if (!r) throw { response: { status: 404, data: { message: 'no route' } } };
      const v = typeof r === 'function' ? r(calls.filter((c) => c.url === url).length) : r;
      if (v && v.__throw) throw v.__throw;
      return v;
    },
    get: (u, c) => h.request('GET', u, c),
    post: (u, d, c) => h.request('POST', u, d, c),
  };
  return h;
}
const API = '/api/v1/pos';
const noSleep = async () => undefined;

// ---------- money property + task literals ----------
test('spreadDiscount property: shares sum to the discount within line bounds', () => {
  const rand = mulberry32(23);
  for (let i = 0; i < 500; i++) {
    const n = 1 + Math.floor(rand() * 8);
    const lines = Array.from({ length: n }, (_, j) => ({ id: j, totalC: Math.floor(rand() * 100000) }));
    const total = lines.reduce((s, l) => s + l.totalC, 0);
    const discount = total === 0 ? 0 : Math.floor(rand() * (total + 1));
    const out = money.spreadDiscount(lines, discount);
    assert.equal(out.reduce((s, o) => s + o.d, 0), discount, `sum case ${i}`);
    out.forEach((o, j) => {
      assert.ok(o.d >= 0, `negative case ${i}`);
      assert.ok(o.d <= lines[j].totalC, `over-line case ${i}`);
    });
  }
  assert.equal(money.maxDiscountAmount(999, 33), 329);
  assert.equal(money.toCents(0.1 + 0.2), 30);
});

// ---------- pending-sale: exact delays, final 422 clears, 500 keeps ----------
test('pending sale default backoff is exactly [1000, 3000, 6000]', async () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage, sleep: noSleep });
  const payload = { items: [{ product_variant_id: 1, qty: 1 }] };
  const d = mgr.prepare(payload);
  const seen = [];
  const sender = async () => {
    const err = new Error('down');
    err.response = undefined;
    throw err;
  };
  await assert.rejects(() => mgr.executeWithRetry(payload, d.key, sender, (a, ms) => void seen.push(ms)));
  assert.deepEqual(seen, [1000, 3000, 6000]);
  assert.notEqual(storage.getItem('pos-pending-sale'), null); // kept after exhausting retries
});

test('pending sale clears the draft on a final 422 and keeps it on 500', async () => {
  const s422 = memStorage();
  const m422 = createPendingSaleManager({ storage: s422, retryDelays: [1], sleep: noSleep });
  const p422 = { items: [] };
  const d422 = m422.prepare(p422);
  await assert.rejects(() => m422.executeWithRetry(p422, d422.key, async () => {
    throw { response: { status: 422, data: { message: 'rejected' } } };
  }));
  assert.equal(s422.getItem('pos-pending-sale'), null);

  const s500 = memStorage();
  const m500 = createPendingSaleManager({ storage: s500, retryDelays: [1], sleep: noSleep });
  const p500 = { items: [] };
  const d500 = m500.prepare(p500);
  await assert.rejects(() => m500.executeWithRetry(p500, d500.key, async () => {
    throw { response: { status: 500, data: { message: 'boom' } } };
  }));
  assert.notEqual(s500.getItem('pos-pending-sale'), null);
});

// ---------- pos-error: cancel identity, 503, 401 mapping ----------
test('cancellations are rethrown untouched (identity)', () => {
  const canceled = Object.assign(new Error('stop'), { code: 'ERR_CANCELED' });
  assert.ok(isCancel(canceled));
  assert.throws(() => normalizeError(canceled), (e) => e === canceled);
});

test('503 maps retryable with Retry-After milliseconds', () => {
  assert.deepEqual(
    { retryable: normalizeError({ response: { status: 503, data: { message: 'busy' }, headers: { 'retry-after': '2' } } }).retryable, ms: normalizeError({ response: { status: 503, data: { message: 'busy' }, headers: { 'retry-after': '2' } } }).retryAfterMs },
    { retryable: true, ms: 2000 },
  );
  assert.equal(
    normalizeError({ response: { status: 503, data: { message: 'busy' }, headers: {} } }).retryAfterMs,
    1000,
  );
  const v = normalizeError({ response: { status: 401, data: { message: 'expired' } } });
  assert.equal(v.code, undefined);
  assert.equal(v.retryable, false);
});

test('call does not retry an idempotency-key 409 (single attempt)', async () => {
  let attempts = 0;
  const http = fakeHttp({
    [`POST ${API}/sales/hold`]: () => {
      attempts += 1;
      return { __throw: { response: { status: 409, data: { message: 'مفتاح عدم التكرار مستخدم' } } } };
    },
  });
  const svc = new PosApiService(http, { sleep: async () => { throw new Error('must not sleep'); } });
  await assert.rejects(() => svc.holdSale([{ id: 1, qty: 1, price: 100, listPrice: 100 }], null, ''));
  assert.equal(attempts, 1);
});

test('call retries 503s with Retry-After then succeeds', async () => {
  const sleeps = [];
  let n = 0;
  const http = fakeHttp({
    [`POST ${API}/sales/hold`]: () => {
      n += 1;
      if (n < 3) {
        return { __throw: { response: { status: 503, data: { message: 'busy' }, headers: { 'retry-after': '1' } } } };
      }
      return { status: 201, data: { data: { id: 9, hold_reference: 'H-9', created_at: new Date().toISOString(), items_payload: [] } } };
    },
  });
  const svc = new PosApiService(http, { sleep: async (ms) => void sleeps.push(ms) });
  const held = await svc.holdSale([{ id: 1, qty: 1, price: 100, listPrice: 100 }], null, '');
  assert.equal(held.serverId, 9);
  assert.deepEqual(sleeps, [1000, 1000]);
});

// ---------- axios interceptor: 401 matrix ----------
test('axios 401 fires onUnauthorized only for the current token', async () => {
  const ok = { status: 200, data: {}, headers: {} };
  const calls = [];
  // Login request: must NOT fire.
  let fired = 0;
  let client = createApiClient({ getToken: () => null, onUnauthorized: () => void (fired += 1) });
  const loginAdapter = async (config) => {
    calls.push(config.url);
    const err = new Error('unauthorized');
    err.config = config;
    err.response = { status: 401, data: { message: 'bad' }, headers: {} };
    throw err;
  };
  await assert.rejects(client.post('/api/v1/auth/login', {}, { adapter: loginAdapter }));
  assert.equal(fired, 0);

  // Stale token (request used an old token): must NOT fire.
  client = createApiClient({ getToken: () => 'new-token', onUnauthorized: () => void (fired += 1) });
  const staleAdapter = async (config) => {
    const err = new Error('unauthorized');
    err.config = { ...config, headers: { Authorization: 'Bearer old-token' } };
    err.response = { status: 401, data: { message: 'expired' }, headers: {} };
    throw err;
  };
  await assert.rejects(client.get('/api/v1/pos/bootstrap', { adapter: staleAdapter }));
  assert.equal(fired, 0);

  // Current token: fires exactly once.
  const curAdapter = async (config) => {
    const err = new Error('unauthorized');
    err.config = { ...config, headers: { Authorization: 'Bearer new-token' } };
    err.response = { status: 401, data: { message: 'expired' }, headers: {} };
    throw err;
  };
  await assert.rejects(client.get('/api/v1/pos/bootstrap', { adapter: curAdapter }));
  assert.equal(fired, 1);
});

// ---------- tabs: self messages are ignored ----------
test('tab coordinator ignores its own hello/here', () => {
  const posted = [];
  const channel = { postMessage: (m) => void posted.push(m), onmessage: null, close: () => undefined };
  let dup = 0;
  const coord = createTabCoordinator({ channelFactory: () => channel, onDuplicate: () => void (dup += 1) });
  coord.start();
  assert.equal(posted.length, 1);
  const ownHello = posted[0];
  channel.onmessage({ data: ownHello }); // looped-back own hello: answers, never duplicates
  channel.onmessage({ data: { ...ownHello, type: 'here' } }); // looped-back own here: ignored
  assert.equal(dup, 0);
  coord.destroy();
});

// ---------- normAr idempotence property ----------
test('normAr is idempotent over random Arabic-like input', () => {
  const { normAr } = require(path.join(POS, 'lib/arabic.ts'));
  let seed = 7;
  const rand = () => {
    seed = (seed * 1103515245 + 12345) & 0x7fffffff;
    return seed / 0x7fffffff;
  };
  const alphabet = ['ا', 'أ', 'إ', 'ة', 'ه', 'ى', 'ي', 'ب', 'ً', 'ُ', '٠', '٥', 'x', ' '];
  for (let i = 0; i < 300; i++) {
    const text = Array.from({ length: 1 + Math.floor(rand() * 12) }, () => alphabet[Math.floor(rand() * alphabet.length)]).join('');
    const once = normAr(text);
    assert.equal(normAr(once), once, `not idempotent for ${JSON.stringify(text)}`);
  }
});

// ---------- scanner: modifier chords clear the buffer ----------
test('scanner modifier chords clear the partial buffer', () => {
  const scans = [];
  let t = 1000;
  const handler = require(path.join(POS, 'lib/barcode-scanner.ts')).createScanDetector({
    onScan: (c) => void scans.push(c),
    now: () => t,
  });
  const press = (key, gap, extra = {}) => {
    t += gap;
    handler({ key, target: null, preventDefault: () => undefined, ...extra });
  };
  '622'.split('').forEach((ch) => press(ch, 10));
  press('Control', 10, { ctrlKey: true });
  '123456'.split('').forEach((ch) => press(ch, 10));
  press('Enter', 10);
  assert.deepEqual(scans, ['123456']);
});

// ---------- receipt archive returns a detached array ----------
test('receipt archive listing is detached from the store', () => {
  clearReceiptArchive();
  const { pushReceipt, getReceiptArchive } = require(path.join(POS, 'lib/receipt-archive.ts'));
  pushReceipt({ invoiceNumber: 'POS-1', total: 1, method: 'cash', received: 1, change: 0, cashA: 1, cardA: 0, cart: [], customer: null, discount: 0, timestamp: 1 });
  const listed = getReceiptArchive();
  listed.push({ invoiceNumber: 'forged' });
  listed.length = 0;
  assert.equal(getReceiptArchive().length, 1);
  clearReceiptArchive();
});
