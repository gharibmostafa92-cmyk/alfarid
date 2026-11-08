'use strict';

require('./helpers/register-ts.cjs');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const money = require(path.join(POS, 'lib/money.ts'));
const { normAr } = require(path.join(POS, 'lib/arabic.ts'));
const { normalizeError, PosApiError } = require(path.join(POS, 'lib/pos-error.ts'));
const mappers = require(path.join(POS, 'services/pos-mappers.ts'));
const { PosApiService } = require(path.join(POS, 'services/pos-api.core.ts'));

// ---------- money ----------
test('toCents/toApi round-trip without float drift', () => {
  assert.equal(money.toCents('150.10'), 15010);
  assert.equal(money.toCents(0.1 + 0.2), 30);
  assert.equal(money.toCents(null), 0);
  assert.equal(money.toApi(15010), '150.10');
  assert.equal(money.fromCents(250), 2.5);
});

test('spreadDiscount distributes exactly by largest remainder', () => {
  const out = money.spreadDiscount([{ id: 1, totalC: 10000 }, { id: 2, totalC: 10000 }, { id: 3, totalC: 10000 }], 100);
  assert.equal(out.reduce((s, o) => s + o.d, 0), 100);
  assert.deepEqual(out.map((o) => o.d).sort(), [33, 33, 34]);
  assert.deepEqual(money.spreadDiscount([{ id: 1, totalC: 500 }], 0), [{ id: 1, d: 0 }]);
});

// ---------- arabic ----------
test('normAr folds digits, diacritics, alef, teh marbuta, yeh', () => {
  assert.equal(normAr('شنطة'), normAr('شنطه'));
  assert.equal(normAr('٦٢٢١٠'), '62210');
  assert.equal(normAr('۱۲۳'), '123');
  assert.equal(normAr('أحمد'), 'احمد');
  assert.equal(normAr('مُحَمَّد'), 'محمد');
  assert.equal(normAr('على'), 'علي');
});

// ---------- errors ----------
test('normalizeError maps network, 403 code, 409 and 422', () => {
  const net = normalizeError({});
  assert.ok(net instanceof PosApiError);
  assert.equal(net.status, 0);
  assert.equal(net.retryable, true);

  const ns = normalizeError({ response: { status: 403, data: { code: 'no_open_shift', message: 'م' } } });
  assert.equal(ns.code, 'no_open_shift');

  assert.equal(normalizeError({ response: { status: 409, data: { message: 'قفل' } } }).retryable, true);
  assert.equal(
    normalizeError({ response: { status: 409, data: { message: 'مفتاح عدم التكرار مستخدم' } } }).retryable,
    false,
  );

  const v = normalizeError({ response: { status: 422, data: { message: 'x', errors: { a: ['b'] } } } });
  assert.deepEqual(v.fields, { a: ['b'] });
});

// ---------- mappers ----------
test('mapShift converts status, numbers and dates', () => {
  const s = mappers.mapShift({
    id: 1, status: 'closed_pending_approval', opened_at: '2026-11-08T18:00:00Z', closed_at: null,
    opening_cash: '200.00', cash_sales_total: '150.00', card_sales_total: '0.00',
    cash_returns_total: '0.00', cash_expenses_total: '0.00', expected_closing_cash: '350.00',
    actual_closing_cash: null, cash_difference: null, treasury: { id: 1, name: 'د', code: 'D' },
  });
  assert.equal(s.status, 'pending_approval');
  assert.equal(s.opening_cash, 200);
  assert.equal(s.actual_closing_cash, null);
  assert.ok(s.opened_at instanceof Date);
  assert.equal(s.treasury_id, 1);
});

test('mapProduct composes name and numeric fields', () => {
  const p = mappers.mapProduct({ id: 2, sku: 'S', barcode: null, product_name: 'شنطة', variant_name: 'أحمر', category_id: '3', unit_price: 140, wholesale_price: 120, available: 5, reorder_point: 2 });
  assert.equal(p.name, 'شنطة - أحمر');
  assert.equal(p.cat, '3');
  assert.equal(p.wholesale, 120);
  assert.equal(p.barcode, '');
});

test('mapHeld keeps serverId and items', () => {
  const h = mappers.mapHeld({ id: 9, hold_reference: 'HOLD-1', created_at: new Date().toISOString(), customer: null, notes: null, items_payload: [{ product_variant_id: 5, qty: 2 }] });
  assert.equal(h.serverId, 9);
  assert.equal(h.ref, 'HOLD-1');
  assert.deepEqual(h.items, [{ id: 5, qty: 2 }]);
});

test('mapPastSale uses net_unit_price and returnable_qty', () => {
  const s = mappers.mapPastSale({
    id: 12, order_number: 'POS-1', created_at: '2026-11-08T18:10:00Z', payment_method: 'cash', cash_amount: '200.00', card_amount: '0.00',
    customer: null, cashier: { id: 1, name: 'ك' },
    items: [{ id: 21, product_variant_id: 5, qty: 2, returned_qty: 1, returnable_qty: 1, unit_price: '150.00', net_unit_price: '100.00' }],
  });
  assert.equal(s.items[0].price, 100);
  assert.equal(s.items[0].returnable_qty, 1);
  assert.equal(s.items[0].id, 5);
});

test('mapTransfer keeps both variant id and transfer item id', () => {
  const t = mappers.mapTransfer({
    id: 10, transfer_number: 'TRF-1', from_branch: { id: 2, name: 'مخزن' }, status: 'shipped', shipped_at: '2026-11-08T18:20:00Z', notes: null,
    items: [{ id: 25, product_variant_id: 1, shipped_qty: '8.0000' }],
  });
  assert.deepEqual(t.items[0], { id: 1, qty: 8, transferItemId: 25 });
});

// ---------- service ----------
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
    delete: (u, c) => h.request('DELETE', u, c),
  };
  return h;
}
const API = '/api/v1/pos';
const catalogRes = (etag = '"v1"') => ({
  status: 200, headers: { etag },
  data: { data: [{ id: 1, sku: 'A', barcode: '111', name: 'شنطة', category_id: '1', unit_price: 100, wholesale_price: 80, available: 5, reorder_point: 2 }], meta: { catalog_version: 'v1', next_cursor: null } },
});
const bootRes = { status: 200, data: { data: { user: { id: 1, name: 'أحمد سمير', permissions: ['pos.access'], branch_id: 1, branch: { id: 1, name: 'ف' } }, branch: { id: 1, name: 'ف', code: 'B' }, shift: null, drawers: [{ id: 1, name: 'د', code: 'D', busy: false, balance: '10.00' }], categories: [{ id: '1', name: 'شنط' }], settings: { max_discount_percent: 20, currency: 'EGP' }, catalog_version: 'v1', held_count: 3 } } };
const noSleep = async () => {};

test('bootstrap populates cashier, branch, settings, held count', async () => {
  const http = fakeHttp({ [`GET ${API}/bootstrap`]: bootRes, [`GET ${API}/catalog`]: catalogRes() });
  const svc = new PosApiService(http, { sleep: noSleep });
  await svc.bootstrap();
  assert.equal((await svc.getCashier()).initials, 'أس');
  assert.equal((await svc.getBranch()).code, 'B');
  assert.equal(svc.getSettings().max_discount_percent, 20);
  assert.equal(svc.getHeldCount(), 3);
  assert.equal(await svc.getCurrentShift(), null);
  assert.equal((await svc.getTreasuries())[0].state, 'free');
});

test('catalog indexes: sync getProductById, ETag 304 reuses cache, Arabic search', async () => {
  let n = 0;
  const http = fakeHttp({ [`GET ${API}/catalog`]: () => (++n === 1 ? catalogRes() : { status: 304, headers: {}, data: '' }) });
  const svc = new PosApiService(http, { sleep: noSleep });
  await svc.loadCatalog(true);
  assert.equal(svc.getProductById(1).name, 'شنطة');
  await svc.loadCatalog(true);
  assert.equal(http.calls[1].a.headers['If-None-Match'], '"v1"');
  assert.equal(svc.getProductById(1).name, 'شنطة');
  assert.equal((await svc.getProducts('all', 'شنطه')).length, 1);
  assert.equal((await svc.getProducts('all', '١١١')).length, 1);
});

test('lookupByBarcode hits local index first, then API, then caches', async () => {
  const http = fakeHttp({
    [`GET ${API}/catalog`]: catalogRes(),
    [`GET ${API}/products/lookup`]: (n) => ({ status: 200, data: { data: n === 1 ? [{ id: 7, sku: 'Z', barcode: '999', product_name: 'جديد', variant_name: null, on_hand: 3, available: 3, unit_price: 50 }] : [] } }),
  });
  const svc = new PosApiService(http, { sleep: noSleep });
  await svc.loadCatalog(true);
  const before = http.calls.length;
  assert.equal((await svc.lookupByBarcode('111')).id, 1);
  assert.equal(http.calls.length, before);
  assert.equal((await svc.lookupByBarcode('999')).id, 7);
  assert.equal(http.calls.length, before + 1);
  assert.equal(svc.getProductById(7).sku, 'Z');
  assert.equal((await svc.lookupByBarcode('999')).id, 7);
  assert.equal(http.calls.length, before + 1);
  assert.equal(await svc.lookupByBarcode('000'), null);
});

test('403 no_open_shift clears current shift and notifies', async () => {
  const shiftData = { id: 1, status: 'open', opened_at: '2026-11-08T18:00:00Z', opening_cash: '0', treasury: { id: 1, name: 'د', code: 'D' } };
  const http = fakeHttp({
    [`POST ${API}/shifts/open`]: { status: 201, data: { data: shiftData } },
    [`GET ${API}/shifts/current/summary`]: { __throw: { response: { status: 403, data: { code: 'no_open_shift', message: 'لا توجد وردية' } } } },
  });
  const svc = new PosApiService(http, { sleep: noSleep });
  let notified = 0;
  svc.subscribe(() => notified++);
  await svc.openShift(1, 0);
  assert.notEqual(await svc.getCurrentShift(), null);
  await assert.rejects(() => svc.getShiftStats(), (e) => e.code === 'no_open_shift');
  assert.equal(await svc.getCurrentShift(), null);
  assert.ok(notified >= 2);
});

test('409 lock conflicts retry twice (300ms, 900ms) then succeed', async () => {
  const delays = [];
  const http = fakeHttp({
    [`POST ${API}/sales/hold`]: (n) => (n < 3
      ? { __throw: { response: { status: 409, data: { message: 'تعارض' } } } }
      : { status: 201, data: { data: { id: 4, hold_reference: 'H-4', created_at: new Date().toISOString(), items_payload: [{ product_variant_id: 1, qty: 1 }] } } }),
  });
  const svc = new PosApiService(http, { sleep: async (ms) => { delays.push(ms); } });
  const held = await svc.holdSale([{ id: 1, qty: 1, price: 100, listPrice: 100 }], null, '');
  assert.equal(held.serverId, 4);
  assert.deepEqual(delays, [300, 900]);
});

test('createSale sends Idempotency-Key and string money amounts', async () => {
  const http = fakeHttp({
    [`POST ${API}/sales`]: { status: 201, data: { data: { id: 5, order_number: 'POS-5' } } },
    [`GET ${API}/shifts/current/summary`]: { status: 200, data: { data: { id: 1, status: 'open', opened_at: '2026-11-08T18:00:00Z', treasury: { id: 1 } } } },
  });
  const svc = new PosApiService(http, { sleep: noSleep });
  const res = await svc.createSale({ items: [{ product_variant_id: 1, qty: 2, unit_price: 100, discount_amount: 10 }], customer_id: null, payment_method: 'cash', cash_amount: 190, card_amount: 0 });
  assert.equal(res.invoiceNumber, 'POS-5');
  const call = http.calls.find((c) => c.url === `${API}/sales`);
  assert.equal(call.a.cash_amount, '190.00');
  assert.equal(call.a.items[0].discount_amount, '10.00');
  assert.ok(call.b.headers['Idempotency-Key']);
});

test('resumeHeldSale posts to resume using cached server id', async () => {
  const http = fakeHttp({
    [`GET ${API}/sales/held`]: { status: 200, data: { data: [{ id: 9, hold_reference: 'HOLD-9', created_at: new Date().toISOString(), items_payload: [{ product_variant_id: 1, qty: 1 }] }] } },
    [`POST ${API}/sales/held/9/resume`]: { status: 200, data: { data: { id: 9, hold_reference: 'HOLD-9', created_at: new Date().toISOString(), items_payload: [{ product_variant_id: 1, qty: 1 }] } } },
  });
  const svc = new PosApiService(http, { sleep: noSleep });
  await svc.getHeldSales();
  const r = await svc.resumeHeldSale('HOLD-9');
  assert.equal(r.items[0].qty, 1);
  assert.ok(http.calls.some((c) => c.url === `${API}/sales/held/9/resume`));
});
