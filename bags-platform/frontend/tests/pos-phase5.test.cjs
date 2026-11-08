'use strict';

require('./helpers/register-ts.cjs');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const { createScanDetector } = require(path.join(POS, 'lib/barcode-scanner.ts'));
const sound = require(path.join(POS, 'lib/sound.ts'));
const { maxDiscountAmount } = require(path.join(POS, 'lib/money.ts'));
const { createPosCartStore } = require(path.join(POS, 'stores/pos-cart.core.ts'));

// ---------- barcode scanner detector ----------
function feeder(opts = {}) {
  let t = 1000;
  const scans = [];
  const handler = createScanDetector({ onScan: (c) => scans.push(c), now: () => t, ...opts });
  const press = (key, gap, target = null) => {
    t += gap;
    let prevented = false;
    handler({ key, target, preventDefault: () => { prevented = true; } });
    return prevented;
  };
  return { press, scans };
}
const type = (f, str, gap = 10) => [...str].forEach((ch) => f.press(ch, gap));

test('fast 6+ chars then Enter is a scan and Enter is prevented', () => {
  const f = feeder();
  type(f, '622103');
  assert.equal(f.press('Enter', 10), true);
  assert.deepEqual(f.scans, ['622103']);
});

test('slow typing is not a scan', () => {
  const f = feeder();
  type(f, '622103', 120);
  assert.equal(f.press('Enter', 10), false);
  assert.deepEqual(f.scans, []);
});

test('fewer than 6 chars is not a scan', () => {
  const f = feeder();
  type(f, '12345');
  f.press('Enter', 10);
  assert.deepEqual(f.scans, []);
});

test('ignores textarea, money inputs and the scan input itself', () => {
  const mk = (tag, attrs = {}) => ({ tagName: tag, closest: (sel) => (attrs[sel] ? {} : null) });
  for (const target of [mk('TEXTAREA'), mk('INPUT', { '[data-money-input]': true }), mk('INPUT', { '[data-scan-input]': true })]) {
    const f = feeder();
    [...'6221031'].forEach((ch) => f.press(ch, 10, target));
    f.press('Enter', 10, target);
    assert.deepEqual(f.scans, []);
  }
});

test('a pause between two scans resets the buffer', () => {
  const f = feeder();
  type(f, '1111111');
  f.press('Enter', 10);
  type(f, '2222222');
  f.press('Enter', 10);
  assert.deepEqual(f.scans, ['1111111', '2222222']);
});

// ---------- sound ----------
test('sound helpers are safe without AudioContext', () => {
  assert.doesNotThrow(() => { sound.beepSuccess(); sound.beepError(); });
});

// ---------- max discount ----------
test('maxDiscountAmount caps a discount at a percent of the subtotal in cents', () => {
  assert.equal(maxDiscountAmount(10000, 20), 2000);
  assert.equal(maxDiscountAmount(10050, 33), 3316);
});

// ---------- cart store ----------
function memStorage() {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), _m: m };
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const prod = (id, price = 100, wholesale = 80) => ({ id, sku: 'S' + id, barcode: '', name: 'P' + id, cat: 'x', price, wholesale, stock: 9, reorder: 1, h: 0 });

function makeStore(over = {}) {
  const calls = [];
  const storage = memStorage();
  const quoteFn = over.quoteFn ?? (async (req) => {
    calls.push(req);
    const lines = req.items.map((i) => ({ product_variant_id: i.product_variant_id, qty: i.qty, unit_price: 90, discount_amount: i.discount_amount, line_total: 90 * i.qty - i.discount_amount, available: 9, error: null }));
    const subtotal = lines.reduce((s, l) => s + l.unit_price * l.qty, 0);
    const discount_total = lines.reduce((s, l) => s + l.discount_amount, 0);
    return { lines, subtotal, discount_total, grand_total: subtotal - discount_total };
  });
  const store = createPosCartStore({ quoteFn, storage, debounceMs: 20, undoMs: 80 });
  return { store, storage, calls };
}

test('cart persists per user and shift, and clears the key on clearCart', () => {
  const { store, storage } = makeStore();
  store.getState().bindContext(7, 3);
  store.getState().addItem(prod(1));
  assert.ok(storage.getItem('pos-cart:7:3'));
  const second = createPosCartStore({ quoteFn: async () => null, storage, debounceMs: 20, undoMs: 80 });
  second.getState().bindContext(7, 3);
  assert.equal(second.getState().cart.length, 1);
  second.getState().bindContext(8, 3);
  assert.equal(second.getState().cart.length, 0);
  store.getState().clearCart();
  assert.equal(storage.getItem('pos-cart:7:3'), null);
});

test('addItem merges quantity and uses wholesale price for wholesale customers', () => {
  const { store } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  store.getState().addItem(prod(1));
  assert.equal(store.getState().cart[0].qty, 2);
  store.getState().setCustomer({ id: 5, name: 'ج', phone: '', tier: 'wholesale', points: 0 });
  store.getState().addItem(prod(2, 100, 80));
  assert.equal(store.getState().cart.find((l) => l.id === 2).price, 80);
});

test('quote is debounced, aborts the previous request and updates line prices', async () => {
  const signals = [];
  const { store, calls } = makeStore({
    quoteFn: async (req, signal) => { signals.push(signal); await wait(30); if (signal.aborted) throw Object.assign(new Error('x'), { name: 'AbortError' }); return { lines: req.items.map((i) => ({ product_variant_id: i.product_variant_id, qty: i.qty, unit_price: 90, discount_amount: 0, line_total: 90 * i.qty, available: 9, error: null })), subtotal: 90, discount_total: 0, grand_total: 90 }; },
  });
  void calls;
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  store.getState().addItem(prod(1));
  await wait(40); // debounce fired once, request in flight
  assert.equal(signals.length, 1);
  assert.equal(store.getState().isQuoting, true);
  store.getState().addItem(prod(1)); // new change aborts in-flight
  await wait(150);
  assert.equal(signals[0].aborted, true);
  assert.equal(signals.length, 2);
  assert.equal(store.getState().isQuoting, false);
  assert.equal(store.getState().cart[0].price, 90);
  assert.equal(store.getState().quote.grand_total, 90);
});

test('line errors from the quote land on the line', async () => {
  const { store } = makeStore({
    quoteFn: async (req) => ({ lines: [{ product_variant_id: 1, qty: 99, unit_price: 100, discount_amount: 0, line_total: 9900, available: 3, error: 'الكمية غير متوفرة' }], subtotal: 9900, discount_total: 0, grand_total: 9900 }),
  });
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  await store.getState().flushQuote();
  assert.equal(store.getState().cart[0].error, 'الكمية غير متوفرة');
});

test('flushQuote runs immediately and resolves with the final quote', async () => {
  const { store, calls } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  const q = await store.getState().flushQuote();
  assert.equal(q.grand_total, 90);
  assert.equal(calls.length, 1);
});

test('invoice discount is spread by largest remainder into per-line discounts', async () => {
  const { store, calls } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1, 100));
  store.getState().addItem(prod(2, 100));
  store.getState().addItem(prod(3, 100));
  store.getState().setDiscount(1);
  await store.getState().flushQuote();
  const last = calls[calls.length - 1];
  const cents = last.items.map((i) => Math.round(i.discount_amount * 100));
  assert.equal(cents.reduce((s, c) => s + c, 0), 100);
  assert.deepEqual([...cents].sort((a, b) => a - b), [33, 33, 34]);
});

test('manual price override sends override_price and unit_price for every line', async () => {
  const { store, calls } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1, 100));
  store.getState().addItem(prod(2, 50));
  store.getState().setLinePrice(1, 70);
  await store.getState().flushQuote();
  const last = calls[calls.length - 1];
  assert.equal(last.override_price, true);
  assert.deepEqual(last.items.map((i) => i.unit_price), [70, 50]);
  assert.equal(store.getState().cart[0].manualPrice, true);
});

test('setCustomer triggers an immediate quote', async () => {
  const { store, calls } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  await store.getState().flushQuote();
  const before = calls.length;
  store.getState().setCustomer({ id: 5, name: 'ج', phone: '', tier: 'wholesale', points: 0 });
  await wait(5);
  assert.equal(calls.length, before + 1);
  assert.equal(calls[calls.length - 1].customer_id, 5);
});

test('removing a line can be undone within the undo window only', async () => {
  const { store } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  store.getState().addItem(prod(2));
  store.getState().setCart((c) => c.filter((l) => l.id !== 1));
  assert.equal(store.getState().cart.length, 1);
  assert.equal(store.getState().lastDeleted.id, 1);
  store.getState().undoDelete();
  assert.equal(store.getState().cart.length, 2);
  store.getState().setCart((c) => c.filter((l) => l.id !== 2));
  await wait(120);
  assert.equal(store.getState().lastDeleted, null);
  store.getState().undoDelete();
  assert.equal(store.getState().cart.length, 1);
});

test('clearing the whole cart is not undoable', () => {
  const { store } = makeStore();
  store.getState().bindContext(1, 1);
  store.getState().addItem(prod(1));
  store.getState().addItem(prod(2));
  store.getState().setCart([]);
  assert.equal(store.getState().lastDeleted, null);
});
