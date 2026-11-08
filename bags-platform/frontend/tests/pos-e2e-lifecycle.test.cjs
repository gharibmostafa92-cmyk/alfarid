'use strict';

require('./helpers/register-ts.cjs');
const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../apps/backoffice/src/pages/pos');
const { toCents, fromCents, spreadDiscount, toApi } = require(path.join(POS, 'lib/money.ts'));
const { createPendingSaleManager } = require(path.join(POS, 'lib/pending-sale.ts'));
const { createTabCoordinator } = require(path.join(POS, 'lib/tab-coordinator.ts'));
const { pushReceipt, getLastReceipt, getReceiptArchive, clearReceiptArchive } = require(path.join(POS, 'lib/receipt-archive.ts'));
const { createPosCartStore } = require(path.join(POS, 'stores/pos-cart.core.ts'));
const { posMockService } = require(path.join(POS, 'services/pos-mock.service.ts'));

function memStorage() {
  const m = new Map();
  return {
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
  };
}

describe('POS End-to-End Cashier Lifecycle', () => {
  test('Full cashier flow: open shift -> add items -> quote -> checkout -> return -> close shift', async () => {
    // 1. Open shift
    const treasuries = await posMockService.getTreasuries();
    const freeDrawer = treasuries.find((t) => t.state === 'free');
    assert.ok(freeDrawer, 'Should have at least one free drawer');
    
    const openingAmount = freeDrawer.balance && freeDrawer.balance > 0 ? freeDrawer.balance : 500;
    const shift = await posMockService.openShift(freeDrawer.id, openingAmount);
    assert.ok(shift.id);
    assert.equal(shift.opening_cash, openingAmount);
    assert.equal(shift.status, 'open');

    // 2. Scan / lookup product
    const product = await posMockService.lookupByBarcode('6221031204115');
    assert.ok(product, 'Product should be found by barcode');
    assert.ok(product.stock > 0, 'Product should have stock');
    const initialStock = product.stock;

    // 3. Cart management with live quote
    let quoteCalls = 0;
    const cartStore = createPosCartStore({
      quoteFn: async (req) => {
        quoteCalls++;
        return {
          lines: req.items.map((i) => ({
            product_variant_id: i.product_variant_id,
            qty: i.qty,
            unit_price: 150,
            discount_amount: 0,
            line_total: i.qty * 150,
            available: initialStock,
            error: null,
          })),
          subtotal: req.items.reduce((s, i) => s + i.qty * 150, 0),
          discount_total: 0,
          grand_total: req.items.reduce((s, i) => s + i.qty * 150, 0),
        };
      },
      storage: memStorage(),
      debounceMs: 5,
    });

    cartStore.getState().addItem(product);
    await new Promise((r) => setTimeout(r, 20));
    assert.equal(cartStore.getState().cart.length, 1);
    assert.equal(cartStore.getState().cart[0].qty, 1);

    // Apply discount
    cartStore.getState().setDiscount(20);
    const cartLines = cartStore.getState().cart;
    const lineCents = cartLines.map((l) => ({ id: l.id, totalC: toCents(l.price * l.qty) }));
    const spread = spreadDiscount(lineCents, toCents(20));
    assert.equal(spread[0].d, 2000); // 20.00 EGP in cents

    // 4. Checkout via idempotent pending sale manager
    const storage = memStorage();
    const pendingMgr = createPendingSaleManager({ storage, retryDelays: [10, 20] });
    const total = 150 - 20; // 130
    const salePayload = {
      items: [
        {
          product_variant_id: product.id,
          qty: 1,
          unit_price: 150,
          discount_amount: 20,
        },
      ],
      customer_id: null,
      payment_method: 'mixed',
      cash_amount: 100,
      card_amount: 30,
      notes: 'كارت ****4821',
    };

    const draft = pendingMgr.prepare(salePayload);
    assert.ok(draft.key, 'Should have generated idempotency key');

    const saleResult = await pendingMgr.executeWithRetry(
      salePayload,
      draft.key,
      (p, k) => posMockService.createSale(p, k)
    );
    assert.ok(saleResult.invoiceNumber, 'Sale created with invoice number');

    // Archive receipt for reprint
    clearReceiptArchive();
    pushReceipt({
      invoiceNumber: saleResult.invoiceNumber,
      total,
      method: 'mixed',
      received: 100,
      change: 0,
      cashA: 100,
      cardA: 30,
      cart: cartLines,
      customer: null,
      discount: 20,
      notes: 'كارت ****4821',
      timestamp: Date.now(),
    });
    const lastRec = getLastReceipt();
    assert.equal(lastRec.invoiceNumber, saleResult.invoiceNumber);

    // 5. Return flow
    const pastSale = await posMockService.getPastSale(saleResult.invoiceNumber);
    assert.ok(pastSale, 'Sale should be retrievable for returns');
    const returnItem = pastSale.items[0];
    const maxReturnable = returnItem.returnable_qty != null ? returnItem.returnable_qty : returnItem.qty;
    assert.equal(maxReturnable, 1);

    await posMockService.processReturn(pastSale.id, [
      { id: returnItem.id, qty: 1, return_to: 'on_hand' },
    ]);

    // 6. Close shift
    const stats = await posMockService.getShiftStats();
    assert.ok(stats.invoices >= 0);
    const expectedClosingCash = shift.opening_cash + stats.cashSales - stats.returns - stats.expenses;
    const closed = await posMockService.closeShift(expectedClosingCash, 'وردية مطابقة تماما');
    assert.ok(closed.id);
    assert.equal(closed.status, 'pending_approval');
  });

  test('Idempotency recovery: simulated network abort preserves draft and succeeds on retry', async () => {
    const storage = memStorage();
    const mgr = createPendingSaleManager({ storage, retryDelays: [5, 10, 15] });

    const payload = {
      items: [{ product_variant_id: 101, qty: 2 }],
      cash_amount: '300.00',
      card_amount: '0.00',
      payment_method: 'cash',
    };

    const draft = mgr.prepare(payload);
    let attempts = 0;
    const usedKeys = [];

    const mockSender = async (p, key) => {
      attempts++;
      usedKeys.push(key);
      if (attempts === 1) {
        // First attempt fails with network disconnect (status 0)
        const netErr = new Error('Network Aborted / Offline');
        netErr.status = 0;
        throw netErr;
      }
      return { order: { id: 99, number: 'POS-NSR-9999' }, invoiceNumber: 'POS-NSR-9999' };
    };

    const result = await mgr.executeWithRetry(payload, draft.key, mockSender);
    assert.equal(result.invoiceNumber, 'POS-NSR-9999');
    assert.equal(attempts, 2, 'Should have succeeded on 2nd attempt');
    assert.equal(usedKeys[0], usedKeys[1], 'Must reuse exact same idempotency key');
    assert.equal(storage.getItem('pos-pending-sale'), null, 'Pending sale cleared from storage on success');
  });

  test('Multi-tab collision detection via BroadcastChannel emulation', () => {
    let duplicated = false;
    const channel = {
      postMessage: (m) => {
        if (m.type === 'hello') {
          channel.onmessage({ data: { type: 'here', from: 'tab-peer' } });
        }
      },
      onmessage: null,
      close: () => {},
    };

    const coordinator = createTabCoordinator({
      channelFactory: () => channel,
      onDuplicate: () => {
        duplicated = true;
      },
    });

    coordinator.start();
    assert.equal(duplicated, true, 'Second tab should detect active peer and flag conflict');
    coordinator.destroy();
  });
});
