'use strict';

require('./helpers/register-ts.cjs');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const POS = path.join(__dirname, '../packages/pos-core/src');
const BACKOFFICE = path.join(__dirname, '../apps/backoffice/src');

const { PosApiService } = require(path.join(POS, 'services/pos-api.core.ts'));
const mappers = require(path.join(POS, 'services/pos-mappers.ts'));
const EP = require(path.join(__dirname, '../packages/api-client/src/endpoints/constants.ts')).API_ENDPOINTS;
const permCatalog = require(path.join(BACKOFFICE, 'pages/admin/admin-mock/api/permission-catalog.ts'));

function makeStubHttp(handler) {
  const calls = [];
  const call = (method) => (url, a, b) => {
    const hasBody = method === 'post';
    const body = hasBody ? a : undefined;
    const config = hasBody ? b : a;
    calls.push({ method, url, body, config });
    return Promise.resolve({ data: handler(method, url, body, config) });
  };
  return {
    calls,
    http: { get: call('get'), post: call('post'), delete: call('delete') },
  };
}

function makeReadyApi(handler) {
  const { calls, http } = makeStubHttp((method, url, body, config) => {
    if (url === EP.POS.SHIFTS_CURRENT || url === EP.POS.SHIFT_SUMMARY) {
      return {
        data: {
          id: 51,
          shift_number: 'SH-51',
          status: 'open',
          opened_at: '2026-11-24T10:00:00Z',
          closed_at: null,
          approved_at: null,
          opening_cash: '500.00',
          cash_sales_total: '0.00',
          card_sales_total: '0.00',
          cash_returns_total: '0.00',
          cash_expenses_total: '100.00',
          expected_closing_cash: '400.00',
          actual_closing_cash: null,
          cash_difference: null,
          notes: null,
          treasury: { id: 1, name: 'درج 1', code: 'DRW-1' },
        },
      };
    }
    return handler(method, url, body, config);
  });
  const api = new PosApiService(http, {
    storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
    clearClientCache: () => {},
  });
  return { api, calls };
}

test('PosApiService exposes shift expense list, update, and void with idempotency keys and shift refresh', async () => {
  const { api, calls } = makeReadyApi((method, url) => {
    if (method === 'get' && url === EP.POS.SALES_EXPENSES) {
      return {
        data: [
          {
            id: 77,
            shift_id: 51,
            category: 'نقل بضاعة',
            amount: '120.50',
            description: 'كرتون',
            created_at: '2026-11-24T10:05:00Z',
            created_by: { id: 2, name: 'كاشير' },
            status: 'posted',
            voided_at: null,
            void_reason: null,
            can_edit: true,
            can_void: true,
          },
        ],
      };
    }
    if (method === 'post' && url.endsWith('/sales/expenses/77')) {
      return {
        data: {
          id: 78,
          shift_id: 51,
          category: 'ضيافة',
          amount: '95.00',
          description: 'تعديل',
          created_at: '2026-11-24T10:06:00Z',
          created_by: { id: 2, name: 'كاشير' },
          status: 'posted',
          voided_at: null,
          void_reason: null,
          can_edit: true,
          can_void: true,
        },
      };
    }
    if (method === 'post' && url.endsWith('/sales/expenses/78/void')) {
      return {
        data: {
          id: 78,
          shift_id: 51,
          category: 'ضيافة',
          amount: '95.00',
          description: 'تعديل',
          created_at: '2026-11-24T10:06:00Z',
          created_by: { id: 2, name: 'كاشير' },
          status: 'voided',
          voided_at: '2026-11-24T10:07:00Z',
          void_reason: 'إدخال بالخطأ',
          can_edit: false,
          can_void: false,
        },
      };
    }
    return { data: null };
  });

  const list = await api.getShiftExpenses();
  assert.equal(list.length, 1);
  assert.equal(list[0].id, 77);
  assert.equal(list[0].amount, 120.5);
  assert.equal(list[0].canEdit, true);

  const updated = await api.updateExpense(77, { amount: 95, category: 'ضيافة', notes: 'تعديل' });
  assert.equal(updated.id, 78);
  assert.equal(updated.amount, 95);
  const updateCall = calls.find((c) => c.method === 'post' && c.url.endsWith('/sales/expenses/77'));
  assert.ok(updateCall?.config?.headers?.['Idempotency-Key']);

  const voided = await api.voidExpense(78, 'إدخال بالخطأ');
  assert.equal(voided.status, 'voided');
  assert.equal(voided.voidReason, 'إدخال بالخطأ');
  const voidCall = calls.find((c) => c.method === 'post' && c.url.endsWith('/sales/expenses/78/void'));
  assert.ok(voidCall?.config?.headers?.['Idempotency-Key']);
  assert.ok(calls.filter((c) => c.url === EP.POS.SHIFT_SUMMARY).length >= 2);
});

test('PosApiService exposes sales history, completed branch orders, cancel, prepare, and direct branch return', async () => {
  const { api, calls } = makeReadyApi((method, url) => {
    if (method === 'get' && url === EP.POS.SALES_HISTORY) {
      return {
        data: [
          {
            id: 901,
            order_number: 'POS-BR1-00901',
            created_at: '2026-11-24T09:00:00Z',
            payment_method: 'cash',
            cash_amount: '450.00',
            card_amount: '0.00',
            grand_total: '450.00',
            customer: { id: 12, name: 'سارة علي' },
            cashier: { id: 2, name: 'كاشير' },
            items: [{ id: 1, product_variant_id: 55, qty: 2, returned_qty: 0, returnable_qty: 2, unit_price: '225.00', net_unit_price: '225.00' }],
          },
        ],
        meta: { next_cursor: null },
      };
    }
    if (method === 'get' && url === EP.POS.INCOMING_ORDERS_COMPLETED) {
      return {
        data: [
          {
            id: 3001,
            order_number: 'ORD-3001',
            channel: 'store',
            status: 'delivered',
            customer_name: 'ندى حسن',
            customer_phone: '01099887766',
            grand_total: '900.00',
            items_count: 2,
            returnable_qty: 2,
            created_at: '2026-11-24T08:00:00Z',
            delivered_at: '2026-11-24T11:00:00Z',
          },
        ],
        meta: { next_cursor: null },
      };
    }
    if (method === 'post' && url.endsWith('/incoming-orders/3001/cancel')) {
      return { data: { id: 3001, order_number: 'ORD-3001', channel: 'store', status: 'cancelled' } };
    }
    if (method === 'post' && url.endsWith('/incoming-orders/3001/prepare')) {
      return { data: { id: 3001, order_number: 'ORD-3001', channel: 'store', status: 'processing', prepared_at: '2026-11-24T12:00:00Z' } };
    }
    if (method === 'post' && url.endsWith('/incoming-orders/3001/returns')) {
      return { data: { return_id: 44, return_number: 'RET-44', status: 'received', total_refund_amount: 450 } };
    }
    return { data: [] };
  });

  const hist = await api.getSalesHistory({ q: 'سارة', limit: 10 });
  assert.equal(hist.data.length, 1);
  assert.equal(hist.data[0].number, 'POS-BR1-00901');

  const completed = await api.getCompletedBranchOrders({ q: '01099', channel: 'all' });
  assert.equal(completed.data.length, 1);
  assert.equal(completed.data[0].order_number, 'ORD-3001');
  assert.equal(completed.data[0].returnable_qty, 2);

  const cancelled = await api.cancelIncomingOrder(3001, 'confirmed', 'طلب العميل');
  assert.equal(cancelled.status, 'cancelled');
  const cancelCall = calls.find((c) => c.url.endsWith('/incoming-orders/3001/cancel'));
  assert.ok(cancelCall?.config?.headers?.['Idempotency-Key']);

  const prepared = await api.prepareIncomingOrder(3001, 'confirmed', [{ product_variant_id: 55, qty: 2 }]);
  assert.equal(prepared.status, 'processing');

  const ret = await api.receiveBranchOrderReturn(3001, {
    reason: 'مقاس غير مناسب',
    reason_type: 'change_of_mind',
    items: [{ order_item_id: 1, qty: 1, return_to: 'on_hand' }],
  });
  assert.equal(ret.status, 'received');
});

test('permission-catalog maps pos.orders.cancel in Arabic and marks it sensitive', () => {
  assert.equal(permCatalog.permLabel('pos.orders.cancel'), 'إلغاء طلبات العملاء في نقطة البيع');
  assert.equal(permCatalog.isSensitive('pos.orders.cancel'), true);
});

const { createPosCartStore, clearPersistedCarts } = require(path.join(POS, 'stores/pos-cart.core.ts'));

function makeMemStorage() {
  const map = new Map();
  return {
    get length() { return map.size; },
    key: (i) => [...map.keys()][i] ?? null,
    getItem: (k) => (map.has(k) ? map.get(k) : null),
    setItem: (k, v) => { map.set(k, String(v)); },
    removeItem: (k) => { map.delete(k); },
    _map: map,
  };
}

test('pos-cart.core prepSession stays isolated from walk-in cart, makes 0 quoteFn calls, and persists per shift context', () => {
  let quoteCalls = 0;
  const storage = makeMemStorage();
  const prevWindow = global.window;
  global.window = { localStorage: storage };

  try {
    const store = createPosCartStore({
      quoteFn: async () => { quoteCalls += 1; return null; },
      storage,
      debounceMs: 10,
    });

    store.getState().bindContext(7, 51);
    store.getState().addItem({
      id: 90, sku: 'WALK-90', barcode: '6220000000090', name: 'حقيبة مشي',
      cat: 'hand', price: 300, wholesale: 250, stock: 5, reorder: 1, h: 10,
    });

    const quoteCallsBeforePrep = quoteCalls;
    store.getState().startPrepSession({
      orderId: 500,
      orderNumber: 'ORD-500',
      channel: 'store',
      expectedStatus: 'confirmed',
      customerName: 'هدى',
      items: [
        { orderItemId: 1, productVariantId: 10, sku: 'SKU-A', barcode: '6221112223334', name: 'شنطة أ', expectedQty: 2, pickedQty: 0 },
        { orderItemId: 2, productVariantId: 20, sku: 'SKU-B', barcode: '', name: 'شنطة ب', expectedQty: 1, pickedQty: 0 },
      ],
      startedAt: '2026-11-24T12:00:00Z',
    });

    // Walk-in cart untouched & 0 extra quoteFn calls
    assert.equal(store.getState().cart.length, 1);
    assert.equal(store.getState().cart[0].id, 90);
    assert.equal(quoteCalls, quoteCallsBeforePrep);

    // Scan by Arabic digits barcode & SKU & variantId
    assert.equal(store.getState().scanPrepItem('٦٢٢١١١٢٢٢٣٣٣٤').status, 'picked');
    assert.equal(store.getState().scanPrepItem('SKU-A').status, 'picked');
    assert.equal(store.getState().scanPrepItem('SKU-A').status, 'already_full');
    assert.equal(store.getState().scanPrepItem('UNKNOWN-999').status, 'not_in_order');
    assert.equal(store.getState().scanPrepItem(20).status, 'picked');

    // Clamp manual quantity
    store.getState().setPrepItemQty(10, 99);
    assert.equal(store.getState().prepSession.items[0].pickedQty, 2);
    store.getState().setPrepItemQty(10, -3);
    assert.equal(store.getState().prepSession.items[0].pickedQty, 0);

    // markAllPrepPicked sets all pickedQty = expectedQty
    store.getState().markAllPrepPicked();
    assert.deepEqual(store.getState().prepSession.items.map((i) => i.pickedQty), [2, 1]);

    // clearCart clears walk-in cart only and preserves prepSession
    store.getState().clearCart();
    assert.equal(store.getState().cart.length, 0);
    assert.equal(store.getState().prepSession?.orderId, 500);

    // Rebinding context restores prepSession from storage
    const store2 = createPosCartStore({ quoteFn: async () => null, storage });
    store2.getState().bindContext(7, 51);
    assert.equal(store2.getState().prepSession?.orderNumber, 'ORD-500');
    assert.deepEqual(store2.getState().prepSession?.items.map((i) => i.pickedQty), [2, 1]);

    // clearPersistedCarts removes both cart and prep keys
    clearPersistedCarts();
    assert.equal(storage.getItem('pos-prep:7:51'), null);
    assert.equal(storage.getItem('pos-cart:7:51'), null);
  } finally {
    if (prevWindow === undefined) delete global.window;
    else global.window = prevWindow;
  }
});

test('mappers normalize completed branch order number and wholesale past sale return items', () => {
  const mappedCompleted = mappers.mapCompletedBranchOrder({
    id: 42,
    number: 'POS-BR1-00042',
    channel: 'pos',
    status: 'delivered',
    customer_name: 'أحمد',
    grand_total: '350.00',
    items_count: 2,
    returnable_qty: 2,
  });
  assert.equal(mappedCompleted.order_number, 'POS-BR1-00042');

  const mappedWholesale = mappers.mapPastSale({
    id: 99,
    channel: 'wholesale',
    order_number: 'WHL-BR1-00099',
    created_at: '2026-11-24T10:00:00Z',
    payment_method: 'credit',
    cash_amount: '0.00',
    card_amount: '0.00',
    grand_total: '500.00',
    customer: { id: 8, name: 'شركة الهدى' },
    items: [
      {
        id: 777,
        product_variant_id: 55,
        product_name: 'شنطة جملة',
        variant_name: 'أسود',
        sku: 'WHL-55',
        qty: 5,
        returned_qty: 1,
        returnable_qty: 4,
        unit_price: '100.00',
        net_unit_price: '100.00',
      },
    ],
  });
  assert.equal(mappedWholesale.channel, 'wholesale');
  assert.equal(mappedWholesale.number, 'WHL-BR1-00099');
  assert.equal(mappedWholesale.items[0].order_item_id, 777);
  assert.equal(mappedWholesale.items[0].name, 'شنطة جملة - أسود');
  assert.equal(mappedWholesale.items[0].sku, 'WHL-55');
  assert.equal(mappedWholesale.items[0].returnable_qty, 4);
});

test('PosApiService exposes shipping companies and incoming order shipment creation with idempotency', async () => {
  const { api, calls } = makeReadyApi((method, url, body) => {
    if (method === 'get' && url === EP.POS.SHIPPING_COMPANIES) {
      return { data: [{ id: 3, name: 'أرامكس', code: 'ARMX' }] };
    }
    if (method === 'post' && url.endsWith('/incoming-orders/3001/shipments')) {
      return {
        data: {
          id: 90,
          order_id: 3001,
          awb_number: body.awb_number || 'ARMX-AUTO',
          status: 'created',
          company_name: 'أرامكس',
          tracking_url: 'https://track.example.com/ARMX-1',
        },
      };
    }
    return { data: [] };
  });

  const companies = await api.getShippingCompanies();
  assert.equal(companies.length, 1);
  assert.equal(companies[0].code, 'ARMX');

  const created = await api.createIncomingOrderShipment(3001, { shipping_company_id: 3, awb_number: 'ARMX-1' });
  assert.equal(created.awb_number, 'ARMX-1');
  const shipCall = calls.find((c) => c.method === 'post' && c.url.endsWith('/incoming-orders/3001/shipments'));
  assert.ok(shipCall?.config?.headers?.['Idempotency-Key']);
});
