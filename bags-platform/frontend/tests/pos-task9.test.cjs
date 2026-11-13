'use strict';

require('./helpers/register-ts.cjs');
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const BACKOFFICE_POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const APPS_POS = path.join(__dirname, '../apps/pos/src');

const mappers = require(path.join(BACKOFFICE_POS, 'services/pos-mappers.ts'));
const { PosApiService } = require(path.join(BACKOFFICE_POS, 'services/pos-api.core.ts'));
const { buildCheckoutPayload } = require(path.join(BACKOFFICE_POS, 'lib/checkout.ts'));
const { createPosCartStore } = require(path.join(BACKOFFICE_POS, 'stores/pos-cart.core.ts'));

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

function memStorage() {
  const m = new Map();
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
  };
}

const API = '/api/v1/pos';
const noSleep = async () => {};

describe('Task 9 POS Frontend Fixes', () => {
  test('CRITICAL-01: processExchange sends net difference (cash/card/zero), never full replacement price', async () => {
    const http = fakeHttp({
      [`POST ${API}/sales/quote`]: { status: 200, data: { data: { lines: [], subtotal: 80, discount_total: 0, grand_total: 80 } } },
      [`POST ${API}/sales/5/exchange`]: { status: 200, data: { data: { exchange_order_id: 6, refund_amount: 0 } } },
    });
    const svc = new PosApiService(http, { sleep: noSleep });

    // Positive net difference (80 repl - 60 ret = 20 due), default cash
    await svc.processExchange(5, [{ id: 1, qty: 1, return_to: 'on_hand' }], [{ id: 2, qty: 1 }], 20, 9);
    const exchangeCalls = () => http.calls.filter((c) => c.url === `${API}/sales/5/exchange`);
    assert.equal(exchangeCalls().at(-1).a.cash_amount, '20.00');
    assert.equal(exchangeCalls().at(-1).a.card_amount, '0.00');

    // Positive net difference paid by card
    await svc.processExchange(5, [{ id: 1, qty: 1, return_to: 'on_hand' }], [{ id: 2, qty: 1 }], 20, 9, { method: 'card' });
    assert.equal(exchangeCalls().at(-1).a.cash_amount, '0.00');
    assert.equal(exchangeCalls().at(-1).a.card_amount, '20.00');

    // Non-positive net difference (refund or even swap): both tenders 0.00
    await svc.processExchange(5, [{ id: 1, qty: 1, return_to: 'on_hand' }], [{ id: 2, qty: 1 }], -15, 9);
    assert.equal(exchangeCalls().at(-1).a.cash_amount, '0.00');
    assert.equal(exchangeCalls().at(-1).a.card_amount, '0.00');
  });

  test('CRITICAL-02: mapShift preserves undefined cash breakdown fields for blind cashiers', () => {
    const blindShift = mappers.mapShift({
      id: 1,
      status: 'closed_pending_approval',
      opened_at: '2026-11-12T10:00:00Z',
      closed_at: '2026-11-12T18:00:00Z',
      opening_cash: '200.00',
      actual_closing_cash: '350.00',
      treasury: { id: 1, name: 'درج 1', code: 'D1' },
      // cash_sales_total, cash_returns_total, cash_expenses_total, expected_closing_cash intentionally absent
    });
    assert.equal(blindShift.cash_sales_total, undefined);
    assert.equal(blindShift.card_sales_total, undefined);
    assert.equal(blindShift.cash_returns_total, undefined);
    assert.equal(blindShift.cash_expenses_total, undefined);
    assert.equal(blindShift.expected_closing_cash, undefined);
  });

  test('HIGH-02: buildCheckoutPayload & createSale propagate override_reason when overridePrice is true', async () => {
    const quote = {
      subtotal: 100,
      discount_total: 0,
      grand_total: 75,
      lines: [{ product_variant_id: 1, qty: 1, unit_price: 75, discount_amount: 0, line_total: 75, available: 5, error: null }],
    };
    const payload = buildCheckoutPayload(quote, {
      customerId: null,
      method: 'cash',
      cashCents: 7500,
      overridePrice: true,
      overrideReason: 'خصم مدير',
      notes: '',
    });
    assert.equal(payload.override_reason, 'خصم مدير');

    const http = fakeHttp({
      [`POST ${API}/sales`]: { status: 201, data: { data: { id: 10, order_number: 'POS-10' } } },
    });
    const svc = new PosApiService(http, { sleep: noSleep });
    await svc.createSale(payload, 'idem-override-1');
    const saleBody = http.calls.find((c) => c.url === `${API}/sales`).a;
    assert.equal(saleBody.override_price, true);
    assert.equal(saleBody.items[0].override_reason, 'خصم مدير');
  });

  test('HIGH-04 & MEDIUM-04: mapHeld preserves full customer object and holdSale / receiveTransfer send Idempotency-Key', async () => {
    const held = mappers.mapHeld({
      id: 14,
      hold_reference: 'HLD-14',
      created_at: new Date().toISOString(),
      notes: 'test',
      customer: { id: 77, name: 'محمد أحمد', phone: '01099998888', type: 'wholesale', status: 'approved', loyalty_points: 50 },
      items_payload: [{ product_variant_id: 3, qty: 2 }],
    });
    assert.equal(held.customer, 'محمد أحمد');
    assert.deepEqual(held.customerObj, {
      id: 77,
      name: 'محمد أحمد',
      phone: '01099998888',
      tier: 'wholesale',
      points: 50,
      status: 'approved',
    });

    const http = fakeHttp({
      [`POST ${API}/sales/hold`]: { status: 201, data: { data: { id: 2, hold_reference: 'H-2', created_at: new Date().toISOString(), items_payload: [] } } },
      [`GET ${API}/transfers/incoming`]: { status: 200, data: { data: [{ id: 8, transfer_number: 'TRF-8', status: 'shipped', items: [{ id: 91, product_variant_id: 1, shipped_qty: '5.0000' }] }] } },
      [`POST ${API}/transfers/8/receive`]: { status: 200, data: { data: { id: 8, status: 'received' } } },
    });
    const svc = new PosApiService(http, { sleep: noSleep });
    await svc.holdSale([{ id: 1, qty: 1, price: 100, listPrice: 100 }], null, 'memo');
    const holdCall = http.calls.find((c) => c.url === `${API}/sales/hold`);
    assert.ok(holdCall.b?.headers?.['Idempotency-Key'], 'holdSale must send Idempotency-Key');

    await svc.getTransfersIn();
    await svc.receiveTransfer(8, { 1: 5 });
    const recCall = http.calls.find((c) => c.url === `${API}/transfers/8/receive`);
    assert.ok(recCall.b?.headers?.['Idempotency-Key'], 'receiveTransfer must send Idempotency-Key');
  });

  test('MEDIUM-01: isWholesaleEligible & cart store deny wholesale price to Pending customers', () => {
    const { isWholesaleEligible } = require(path.join(BACKOFFICE_POS, 'lib/wholesale.ts'));
    assert.equal(isWholesaleEligible({ id: 1, name: 'A', phone: '', tier: 'wholesale', points: 0, status: 'Approved' }), true);
    assert.equal(isWholesaleEligible({ id: 2, name: 'B', phone: '', tier: 'wholesale', points: 0, status: 'approved' }), true);
    assert.equal(isWholesaleEligible({ id: 3, name: 'C', phone: '', tier: 'wholesale', points: 0, status: 'Pending' }), false);
    assert.equal(isWholesaleEligible({ id: 4, name: 'D', phone: '', tier: 'wholesale', points: 0, status: null }), false);
    assert.equal(isWholesaleEligible({ id: 5, name: 'E', phone: '', tier: 'retail', points: 0, status: 'Approved' }), false);

    const store = createPosCartStore({ quoteFn: async () => null, storage: memStorage(), debounceMs: 20, undoMs: 80 });
    store.getState().bindContext(1, 1);
    store.getState().setCustomer({ id: 3, name: 'تحت المراجعة', phone: '010', tier: 'wholesale', points: 0, status: 'Pending' });
    store.getState().addItem({ id: 10, sku: 'S10', barcode: '', name: 'P10', cat: 'x', price: 200, wholesale: 150, stock: 5, reorder: 1, h: 0 });
    assert.equal(store.getState().cart[0].price, 200);
  });

  test('MEDIUM-02: apps/pos normAr preserves hyphens in SKU and folds Arabic chars cleanly', () => {
    const { normAr } = require(path.join(APPS_POS, 'lib/arabic.ts'));
    assert.equal(normAr('BAG-101'), 'bag-101');
    assert.equal(normAr('شنطة'), normAr('شنطه'));
    assert.equal(normAr('٦٢٢١٠'), '62210');
    assert.equal(normAr('۱۲۳'), '123');
    assert.equal(normAr('أحمد'), 'احمد');
    assert.equal(normAr('مُحَمَّد'), 'محمد');
    assert.equal(normAr('على'), 'علي');
  });

  test('MEDIUM-03: getCustomers forwards search query and AbortSignal', async () => {
    const http = fakeHttp({
      [`GET ${API}/customers`]: { status: 200, data: { data: [] } },
    });
    const svc = new PosApiService(http, { sleep: noSleep });
    const ac = new AbortController();
    await svc.getCustomers('01099', ac.signal);
    const call = http.calls.find((c) => c.url === `${API}/customers`);
    assert.equal(call.a.params.q, '01099');
    assert.equal(call.a.signal, ac.signal);
  });

  test('CRITICAL-03 & CRITICAL-05: apps/pos wires real PosApiService, blocks PROD mock, and drops demo stubs', () => {
    const dataSource = fs.readFileSync(path.join(APPS_POS, 'services/pos-data-source.ts'), 'utf8');
    assert.match(dataSource, /if\s*\(import\.meta\.env\.PROD\s*&&\s*mockRequested\)/);
    assert.match(dataSource, /export const pos = mockRequested \? posMockService : posApiService;/);
    assert.equal(fs.existsSync(path.join(APPS_POS, 'pages/CashierScreen.tsx')), false);
    assert.equal(fs.existsSync(path.join(APPS_POS, 'stores/pos-shift.store.ts')), false);

    for (const root of [BACKOFFICE_POS, APPS_POS]) {
      const stocktake = fs.readFileSync(path.join(root, 'components/inventory/StocktakePage.tsx'), 'utf8');
      assert.equal(stocktake.includes('getProductById(101)'), false);
      assert.equal(stocktake.includes('خمسة وتمانين'), false);
      assert.equal(stocktake.includes("words = 'اتناشر'"), false);
      assert.equal(stocktake.includes('setInterval'), false);

      const reqTab = fs.readFileSync(path.join(root, 'components/inventory/RequestTab.tsx'), 'utf8');
      assert.equal(reqTab.includes('id: 106'), false);

      const retModal = fs.readFileSync(path.join(root, 'components/returns/ReturnModal.tsx'), 'utf8');
      assert.match(retModal, /import\.meta\.env\.DEV[\s\S]*loadExample/);
    }

    // Exercise apps/pos PosApiService directly too
    const { PosApiService: AppPosApiService } = require(path.join(APPS_POS, 'services/pos-api.core.ts'));
    assert.equal(typeof AppPosApiService, 'function');
  });
});
