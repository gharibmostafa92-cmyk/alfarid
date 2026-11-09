'use strict';

require('./helpers/register-ts.cjs');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const { createPendingSaleManager } = require(path.join(POS, 'lib/pending-sale.ts'));
const { createTabCoordinator } = require(path.join(POS, 'lib/tab-coordinator.ts'));

// ---------- pending sale & idempotency retry ----------
function memStorage() {
  const m = new Map();
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
  };
}

test('an unconfirmed sale keeps its key and cannot be overwritten by a different payment', () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage });
  const p1 = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const d1 = mgr.prepare(p1);
  assert.ok(d1.key);
  const d1_again = mgr.prepare(p1);
  assert.equal(d1_again.key, d1.key);

  const p2 = { items: [{ product_variant_id: 1, qty: 2 }], cash_amount: '200.00' };
  assert.throws(() => mgr.prepare(p2), /تأكيد/);
  assert.equal(mgr.getSaved().key, d1.key);
  mgr.clear();
  const d2 = mgr.prepare(p2);
  assert.notEqual(d2.key, d1.key);
});

test('opening checkout in a previous version does not leave a recoverable sale', () => {
  const storage = memStorage();
  storage.setItem('pos-pending-sale', JSON.stringify({
    key: 'legacy', hash: 'preview', payload: { items: [{ id: 1, qty: 2 }], t: 42 },
  }));
  assert.equal(createPendingSaleManager({ storage }).getSaved(), null);
});

test('a pending payment cannot be replayed under another cashier or shift', async () => {
  const storage = memStorage();
  const first = createPendingSaleManager({ storage, owner: '1:10' });
  const payload = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const draft = first.prepare(payload);
  const second = createPendingSaleManager({ storage, owner: '2:11' });
  let sent = false;
  await assert.rejects(second.executeWithRetry(payload, draft.key, async () => { sent = true; }), /الوردية/);
  assert.equal(sent, false);
  assert.equal(first.getSaved().key, draft.key);
});

test('checkout collects the quoted total and submits the quoted discounts and manual prices', () => {
  const { buildCheckoutPayload } = require(path.join(POS, 'lib/checkout.ts'));
  const quote = {
    subtotal: 22.01, discount_total: 1.01, grand_total: 21,
    lines: [
      { product_variant_id: 1, qty: 2, unit_price: 10, discount_amount: 0.99, line_total: 19.01, available: 4, error: null },
      { product_variant_id: 2, qty: 1, unit_price: 2.01, discount_amount: 0.02, line_total: 1.99, available: 8, error: null },
    ],
  };
  const payload = buildCheckoutPayload(quote, {
    customerId: 3, method: 'mixed', cashCents: 1034, overridePrice: true, notes: '',
  });
  assert.deepEqual(payload, {
    customer_id: 3, payment_method: 'mixed', cash_amount: 10.34, card_amount: 10.66,
    override_price: true, notes: '',
    items: [
      { product_variant_id: 1, qty: 2, unit_price: 10, discount_amount: 0.99 },
      { product_variant_id: 2, qty: 1, unit_price: 2.01, discount_amount: 0.02 },
    ],
  });
});

test('pending sale retries 3 times on network error (status 0) using exact same idempotency key', async () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage, retryDelays: [10, 20, 30] });
  const payload = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const d = mgr.prepare(payload);

  let attempts = 0;
  const sentKeys = [];
  const sender = async (p, key) => {
    attempts++;
    sentKeys.push(key);
    if (attempts < 3) {
      const err = new Error('Network timeout');
      err.response = undefined; // status 0
      throw err;
    }
    return { order: { id: 1, number: 'POS-1' }, invoiceNumber: 'POS-1' };
  };

  const res = await mgr.executeWithRetry(payload, d.key, sender);
  assert.equal(res.invoiceNumber, 'POS-1');
  assert.equal(attempts, 3);
  assert.deepEqual(sentKeys, [d.key, d.key, d.key]);
  assert.equal(storage.getItem('pos-pending-sale'), null); // cleared on success
});

test('pending sale persists draft if all retries fail with network error', async () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage, retryDelays: [5, 5, 5] });
  const payload = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const d = mgr.prepare(payload);

  const sender = async () => {
    throw new Error('Connection refused');
  };

  await assert.rejects(() => mgr.executeWithRetry(payload, d.key, sender));
  const saved = JSON.parse(storage.getItem('pos-pending-sale'));
  assert.equal(saved.key, d.key);
  assert.deepEqual(saved.payload, payload);
});

test('a retry rejected after a timeout preserves the unconfirmed sale', async () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage, retryDelays: [0] });
  const payload = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const draft = mgr.prepare(payload);
  let attempts = 0;
  await assert.rejects(mgr.executeWithRetry(payload, draft.key, async () => {
    if (++attempts === 1) throw new Error('Connection lost');
    throw { response: { status: 403, data: { code: 'no_open_shift', message: 'لا توجد وردية' } } };
  }));
  assert.equal(mgr.getSaved()?.key, draft.key);
});

// ---------- multi-tab duplicate coordinator ----------
test('tab coordinator detects another active tab and triggers onDuplicate', () => {
  let dup = false;
  const channel = {
    postMessage: (msg) => {
      if (msg.type === 'hello') {
        // another tab responds
        channel.onmessage({ data: { type: 'here', from: 'tab-2' } });
      }
    },
    onmessage: null,
    close: () => {},
  };

  const coord = createTabCoordinator({
    channelFactory: () => channel,
    onDuplicate: () => { dup = true; },
  });

  coord.start();
  assert.equal(dup, true);
  coord.destroy();
});

// ---------- receipt archive ----------
const { pushReceipt, getLastReceipt, getReceiptArchive, clearReceiptArchive } = require(path.join(POS, 'lib/receipt-archive.ts'));

test('receipt archive stores up to 5 receipts in FIFO order', () => {
  clearReceiptArchive();
  assert.equal(getLastReceipt(), null);

  for (let i = 1; i <= 7; i++) {
    pushReceipt({
      invoiceNumber: `POS-${i}`,
      total: i * 100,
      method: 'cash',
      received: i * 100,
      change: 0,
      cashA: i * 100,
      cardA: 0,
      cart: [],
      customer: null,
      discount: 0,
      timestamp: Date.now() + i,
    });
  }

  const archive = getReceiptArchive();
  assert.equal(archive.length, 5);
  // Last pushed is at index 0 (POS-7)
  assert.equal(getLastReceipt().invoiceNumber, 'POS-7');
  assert.deepEqual(archive.map((r) => r.invoiceNumber), ['POS-7', 'POS-6', 'POS-5', 'POS-4', 'POS-3']);
});

// ---------- discount spread & cents precision ----------
const { spreadDiscount, toCents, fromCents } = require(path.join(POS, 'lib/money.ts'));

test('checkout discount distribution matches exact total cents without drift', () => {
  const cart = [
    { id: 1, price: 150.00, qty: 1 },
    { id: 2, price: 150.00, qty: 1 },
    { id: 3, price: 150.00, qty: 1 },
  ];
  const discount = 50.00; // 5000 cents across 3 lines of 15000 cents each

  const lineCents = cart.map((l) => ({ id: l.id, totalC: toCents(l.price * l.qty) }));
  const distributed = spreadDiscount(lineCents, toCents(discount));

  const totalDiscountCents = distributed.reduce((s, d) => s + d.d, 0);
  assert.equal(totalDiscountCents, 5000); // exactly 50 EGP
  assert.deepEqual(distributed.map((d) => d.d), [1667, 1667, 1666]); // largest remainder distribution
});

// ---------- return quantity capping ----------
test('returnable quantity caps maximum returnable items', () => {
  const item = {
    id: 10,
    qty: 5,
    returnable_qty: 2, // 3 already returned
    price: 200,
  };

  const retMax = item.returnable_qty != null ? item.returnable_qty : item.qty;
  assert.equal(retMax, 2);

  const desiredQty = 4;
  const clamped = Math.min(retMax, desiredQty);
  assert.equal(clamped, 2);
});

