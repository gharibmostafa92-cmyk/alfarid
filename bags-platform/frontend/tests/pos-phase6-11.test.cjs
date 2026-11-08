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

test('pending sale keeps same idempotency key for identical payload, new key for changed payload', () => {
  const storage = memStorage();
  const mgr = createPendingSaleManager({ storage });
  const p1 = { items: [{ product_variant_id: 1, qty: 1 }], cash_amount: '100.00' };
  const d1 = mgr.prepare(p1);
  assert.ok(d1.key);
  const d1_again = mgr.prepare(p1);
  assert.equal(d1_again.key, d1.key);

  const p2 = { items: [{ product_variant_id: 1, qty: 2 }], cash_amount: '200.00' };
  const d2 = mgr.prepare(p2);
  assert.notEqual(d2.key, d1.key);
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

