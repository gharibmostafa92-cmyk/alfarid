'use strict';

/* Archived task6.txt roadmap (F-03..F-18) frontend regression coverage.
 * Only assertions missing from the existing pos suites live here; nothing
 * below duplicates pos-phase2/5/6-11/lifecycle/tasktxt-gaps/safe-return-path.
 */
require('./helpers/register-ts.cjs');
// The shared hook only covers .ts; this suite also needs egp from a .tsx UI module.
const fs = require('node:fs');
const ts = require('typescript');
require.extensions['.tsx'] = (module, filename) => {
  const out = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true, jsx: ts.JsxEmit.React },
    fileName: filename,
  });
  module._compile(out.outputText, filename);
};
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../packages/pos-core/src');
const money = require(path.join(POS, 'lib/money.ts'));
const { mapHeld, mapPastSale } = require(path.join(POS, 'services/pos-mappers.ts'));
const { createPendingSaleManager } = require(path.join(POS, 'lib/pending-sale.ts'));
const { createScanDetector, isRapidScannerKey } = require(path.join(POS, 'lib/barcode-scanner.ts'));
const archive = require(path.join(POS, 'lib/receipt-archive.ts'));
const { PosApiService } = require(path.join(POS, 'services/pos-api.core.ts'));
const { egp } = require(path.join(POS, 'components/ui/SharedUI.tsx'));

// Same URL shapes as API_ENDPOINTS.POS (the package is not resolvable from tests/).
const API = '/api/v1/pos';
const EP = {
  SALES_HOLD: `${API}/sales/hold`,
  SALES_HELD: `${API}/sales/held`,
  SALE_HELD_RESUME: (id) => `${API}/sales/held/${id}/resume`,
  SHIFTS_OPEN: `${API}/shifts/open`,
  SHIFTS_CLOSE: `${API}/shifts/close`,
};

function memStorage() {
  const m = new Map();
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => void m.set(k, String(v)),
    removeItem: (k) => void m.delete(k),
    keys: () => [...m.keys()],
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

const shiftJson = (over = {}) => ({
  id: 7,
  shift_number: 'S-7',
  status: 'open',
  treasury_id: 5,
  treasury: { id: 5, name: 'Drawer', code: 'D1', state: 'busy', last: '', balance: 0 },
  opening_cash: 50,
  opened_at: new Date().toISOString(),
  ...over,
});

describe('F-03 held sale manual-price flag survives hold and resume', () => {
  test('holdSale persists manual_price per line', async () => {
    const http = fakeHttp({
      [`POST ${EP.SALES_HOLD}`]: {
        data: { data: { id: 9, hold_reference: 'H-1', items_payload: [], notes: '', created_at: new Date().toISOString() } },
      },
    });
    const pos = new PosApiService(http);
    await pos.holdSale(
      [{ id: 1, qty: 2, price: 400, listPrice: 500, manualPrice: false }],
      null,
      'note',
      0,
    );
    const body = http.calls[0].a;
    assert.equal(body.items[0].manual_price, false);
  });

  test('holdSale marks cashier-overridden lines as manual', async () => {
    const http = fakeHttp({
      [`POST ${EP.SALES_HOLD}`]: {
        data: { data: { id: 9, hold_reference: 'H-1', items_payload: [], notes: '', created_at: new Date().toISOString() } },
      },
    });
    const pos = new PosApiService(http);
    await pos.holdSale(
      [{ id: 1, qty: 1, price: 620, listPrice: 500, manualPrice: true }],
      null,
      '',
      0,
    );
    assert.equal(http.calls[0].a.items[0].manual_price, true);
  });

  test('resumeHeldSale keeps the held row visible locally (server lease protects it)', async () => {
    const heldRow = {
      id: 11,
      hold_reference: 'H-11',
      customer: null,
      notes: '',
      items_payload: [{ product_variant_id: 1, qty: 2, unit_price: 400, manual_price: false }],
      created_at: new Date().toISOString(),
    };
    const http = fakeHttp({
      [`GET ${EP.SALES_HELD}`]: { data: { data: [heldRow] } },
      [`POST ${EP.SALE_HELD_RESUME(11)}`]: {
        data: { data: { ...heldRow, resumed_by: 3, resumed_at: new Date().toISOString() } },
      },
    });
    const pos = new PosApiService(http);
    await pos.getHeldSales();
    assert.equal(pos.getHeldCount(), 1);
    const resumed = await pos.resumeHeldSale('H-11');
    assert.equal(resumed.items[0].manual_price, false);
    // The row must stay in the drawer: hiding it locally while the server
    // lease is alive creates ghost invoices that reappear with 409s.
    assert.equal(pos.getHeldCount(), 1);
  });

  test('mapHeld carries manual_price and discounts through', () => {
    const h = mapHeld({
      hold_reference: 'H-2',
      created_at: new Date().toISOString(),
      items_payload: [{ product_variant_id: 4, qty: 1, unit_price: 620, discount_amount: 5, manual_price: true }],
    });
    assert.equal(h.items[0].manual_price, true);
    assert.equal(h.items[0].discount_amount, 5);
  });
});

describe('F-05 pending sales are isolated per shift owner with a review discard', () => {
  test('another shift owner never sees my draft as recoverable', () => {
    const storage = memStorage();
    const mine = createPendingSaleManager({ storage, owner: 'cashier2:shift99' });
    mine.prepare({ total: 100 });
    const other = createPendingSaleManager({ storage, owner: 'cashier3:shift100' });
    assert.equal(other.getSaved(), null);
  });

  test('clearing only touches the caller owner draft', () => {
    const storage = memStorage();
    createPendingSaleManager({ storage, owner: 'cashier2:shift99' }).prepare({ total: 100 });
    createPendingSaleManager({ storage, owner: 'cashier3:shift100' }).clear();
    const mine = createPendingSaleManager({ storage, owner: 'cashier2:shift99' });
    assert.notEqual(mine.getSaved(), null);
  });

  test('stale drafts are listed for review and discardable after review', () => {
    const storage = memStorage();
    createPendingSaleManager({ storage, owner: 'cashier2:shift99' }).prepare({ total: 100 });
    const other = createPendingSaleManager({ storage, owner: 'cashier3:shift100' });
    const stale = other.listDrafts().filter((d) => d.owner !== 'cashier3:shift100');
    assert.equal(stale.length, 1);
    assert.equal(stale[0].owner, 'cashier2:shift99');
    other.discardDraft('cashier2:shift99');
    assert.equal(other.listDrafts().length, 0);
  });

  test('legacy unscoped drafts are adopted by their matching owner', () => {
    const storage = memStorage();
    storage.setItem('pos-pending-sale', JSON.stringify({ hash: 'h', key: 'k', payload: { total: 5 }, owner: 'cashier2:shift99' }));
    const mine = createPendingSaleManager({ storage, owner: 'cashier2:shift99' });
    assert.notEqual(mine.getSaved(), null);
    const other = createPendingSaleManager({ storage, owner: 'cashier9:shift1' });
    assert.equal(other.getSaved(), null);
  });
});

describe('F-07 proportional cent math matches the server estimateReturnValue', () => {
  test('indivisible discount splits to the exact cent (3 x 100 - 10, return 1)', () => {
    // Server: round(1 * 29000 / 3) - 0 = 9667.
    assert.equal(money.proportionalRefundCents(29000, 3, 0, 1, 0), 9667);
  });

  test('second unit accounts for the previous refund (returns 19333 - 9667)', () => {
    assert.equal(money.proportionalRefundCents(29000, 3, 1, 1, 9667), 9666);
  });

  test('full return refunds the whole line and never goes negative', () => {
    assert.equal(money.proportionalRefundCents(29000, 3, 0, 3, 0), 29000);
    assert.equal(money.proportionalRefundCents(29000, 3, 3, 1, 29000), 0);
  });

  test('mapPastSale carries line_total and refund history for the client math', () => {
    const s = mapPastSale({
      id: 1,
      order_number: 'POS-1',
      created_at: new Date().toISOString(),
      cashier: null,
      customer: null,
      payment_method: 'cash',
      items: [{
        product_variant_id: 9,
        qty: 3,
        unit_price: '100.00',
        discount_amount: '10.00',
        net_unit_price: '96.67',
        line_total: '290.00',
        previous_refunded_amount: '96.67',
      }],
    });
    assert.equal(s.items[0].line_total, 290);
    assert.equal(s.items[0].previous_refunded_amount, 96.67);
  });
});

describe('F-10 shift open and close send stable idempotency keys', () => {
  test('openShift sends an Idempotency-Key that survives a failed first attempt', async () => {
    const http = fakeHttp({
      [`POST ${EP.SHIFTS_OPEN}`]: (n) => {
        // First attempt: network timeout after the server committed.
        if (n === 1) throw { message: 'timeout' };
        return { data: { data: shiftJson() } };
      },
    });
    const pos = new PosApiService(http);
    await assert.rejects(pos.openShift(5, 50));
    const k1 = http.calls[0].b.headers['Idempotency-Key'];
    assert.ok(k1);
    const shift = await pos.openShift(5, 50);
    assert.equal(http.calls[1].b.headers['Idempotency-Key'], k1);
    assert.equal(shift.id, 7);
  });

  test('closeShift sends an Idempotency-Key', async () => {
    const http = fakeHttp({
      [`POST ${EP.SHIFTS_CLOSE}`]: { data: { data: shiftJson({ status: 'closed_pending_approval' }) } },
    });
    const pos = new PosApiService(http);
    await pos.closeShift(50, null);
    assert.ok(http.calls[0].b.headers['Idempotency-Key']);
  });
});

describe('F-12 scanner hardening', () => {
  test('55ms-spaced scanner bursts still form one barcode', () => {
    let got = null;
    let t = 0;
    const detect = createScanDetector({ onScan: (c) => { got = c; }, now: () => t });
    const press = (key, dt) => { t += dt; detect({ key, target: null, preventDefault: () => {} }); };
    '622123456789'.split('').forEach((ch) => press(ch, 50));
    press('Enter', 50);
    assert.equal(got, '622123456789');
  });

  test('shift chords inside a burst do not corrupt the barcode', () => {
    let got = null;
    let t = 0;
    const detect = createScanDetector({ onScan: (c) => { got = c; }, now: () => t });
    const press = (key, dt) => { t += dt; detect({ key, target: null, preventDefault: () => {} }); };
    press('Shift', 5);
    'BAG001'.split('').forEach((ch) => press(ch, 20));
    press('Enter', 20);
    assert.equal(got, 'BAG001');
  });

  test('rapid key runs are detectable so shortcuts can stand down', () => {
    assert.equal(isRapidScannerKey(1000, 990, 60), true);
    assert.equal(isRapidScannerKey(1000, 500, 60), false);
  });
});

describe('F-18 mock service mirrors the server contract', () => {
  const { posMockService } = require(path.join(POS, 'services/pos-mock.service.ts'));

  test('createSale replays the same invoice for the same idempotency key', async () => {
    const product = (await posMockService.getProducts('all', '', false))[0];
    const payload = {
      items: [{ product_variant_id: product.id, qty: 1 }],
      customer_id: null,
      payment_method: 'cash',
      cash_amount: 100,
      card_amount: 0,
    };
    const before = (await posMockService.lookupByBarcode(product.barcode)).stock;
    const first = await posMockService.createSale(structuredClone(payload), 'mock-key-1');
    const second = await posMockService.createSale(structuredClone(payload), 'mock-key-1');
    assert.equal(second.invoiceNumber, first.invoiceNumber);
    assert.equal(second.order.id, first.order.id);
    const after = (await posMockService.lookupByBarcode(product.barcode)).stock;
    assert.equal(after, before - 1);
  });

  test('processReturn caps quantities at the sold amount and prices from the sale', async () => {
    const product = (await posMockService.getProducts('all', '', false))[1];
    const sale = await posMockService.createSale(
      {
        items: [{ product_variant_id: product.id, qty: 2, unit_price: 50 }],
        customer_id: null,
        payment_method: 'cash',
        cash_amount: 100,
        card_amount: 0,
      },
      `mock-key-${Date.now()}`,
    );
    await assert.rejects(
      posMockService.processReturn(sale.order.id, [{ id: product.id, qty: 3, return_to: 'on_hand' }]),
    );
    const res = await posMockService.processReturn(sale.order.id, [
      { id: product.id, qty: 2, return_to: 'on_hand' },
    ]);
    assert.equal(res.cash_refund_amount, 100);
    assert.equal(res.card_refund_amount, 0);
  });

  test('processExchange honors the card payment method', async () => {
    const product = (await posMockService.getProducts('all', '', false))[2];
    const sale = await posMockService.createSale(
      {
        items: [{ product_variant_id: product.id, qty: 1, unit_price: 100 }],
        customer_id: null,
        payment_method: 'card',
        cash_amount: 0,
        card_amount: 100,
      },
      `mock-key-${Date.now()}-x`,
    );
    const before = await posMockService.getShiftStats();
    const res = await posMockService.processExchange(
      sale.order.id,
      [{ id: product.id, qty: 1, return_to: 'on_hand' }],
      [{ id: product.id, qty: 1 }],
      60,
      null,
      { method: 'card' },
    );
    const after = await posMockService.getShiftStats();
    assert.equal(res.cash_refund_amount, 0);
    assert.equal(after.cashSales, before.cashSales);
    assert.equal(after.cardSales, before.cardSales + 60);
  });
});

describe('F-16/F-17 receipt archive isolation', () => {
  const receipt = (cashierId) => ({
    invoiceNumber: `INV-${cashierId}`,
    total: 10,
    method: 'cash',
    received: 10,
    change: 0,
    cashA: 10,
    cardA: 0,
    cart: [],
    customer: null,
    discount: 0,
    cashier: { id: cashierId },
    timestamp: Date.now(),
  });

  test('cashiers only see their own last receipt', () => {
    const storage = memStorage();
    archive.pushReceipt(receipt(1), { cashierId: 1 }, storage);
    assert.equal(archive.getLastReceipt(2, storage), null);
    assert.equal(archive.getLastReceipt(1, storage).invoiceNumber, 'INV-1');
    assert.equal(archive.getLastReceipt(undefined, storage), null);
  });

  test('clearing is scoped; logout clears everything', () => {
    const storage = memStorage();
    archive.pushReceipt(receipt(1), { cashierId: 1 }, storage);
    archive.pushReceipt(receipt(2), { cashierId: 2 }, storage);
    archive.clearReceiptArchive(2, storage);
    assert.equal(archive.getLastReceipt(2, storage), null);
    assert.equal(archive.getLastReceipt(1, storage).invoiceNumber, 'INV-1');
    archive.clearReceiptArchive(undefined, storage);
    assert.equal(archive.getLastReceipt(1, storage), null);
  });

  test('egp keeps its own sign so Cart must not prepend another', () => {
    const digits = (s) => s.replace(/[^٠-٩0-9]/g, '');
    assert.ok(!/^[-−]/.test(egp(20)));
    // Same digits, sign baked in: prepending another minus double-signs.
    assert.equal(digits(egp(-20)), digits(egp(20)));
    assert.notEqual(egp(-20), egp(20));
  });
});
