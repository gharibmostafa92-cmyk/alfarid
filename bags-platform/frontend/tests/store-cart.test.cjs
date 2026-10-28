const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const { webcrypto } = require('node:crypto');
const ts = require('typescript');

const filename = path.join(__dirname, '../apps/storefront/src/stores/cart-store.ts');
const token = '1b5d1234-0000-4000-8000-123456789012';
const cart = (quantity = 1, total = 750) => ({ id: 1, customer_id: null, cart_token: token,
  items: quantity ? [{ id: 101, product_variant_id: 88, product_id: 7, product_name: 'حقيبة', sku: 'BAG-01', qty: quantity, unit_price: 250, line_total: 250 * quantity, available: 5, priced: true }] : [],
  totals: { subtotal: total, items_total: total, total, coupon_discount: 0, pricing_discount: 0, coupon_code: null },
});

function load(api, values = new Map(), auth = { token: null }) {
  const modules = new Map();
  const sourceRoot = path.resolve(__dirname, '../apps/storefront/src');
  const localStorage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key) };
  function evaluate(file) {
    if (modules.has(file)) return modules.get(file).exports;
    const moduleObject = { exports: {} };
    modules.set(file, moduleObject);
    const code = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    new Function('exports', 'module', 'require', 'localStorage', 'navigator', 'crypto', code)(
      moduleObject.exports, moduleObject,
      name => name === '@alfarid/api-client' ? { cartEndpoints: api, getAuthToken: () => auth.token }
        : name.startsWith('@/') ? evaluate(path.join(sourceRoot, `${name.slice(2)}.ts`)) : createRequire(file)(name),
      localStorage, undefined, webcrypto,
    );
    return moduleObject.exports;
  }
  return evaluate(filename).useCartStore;
}

test('add sends only variant/quantity/session and trusts the server total', async () => {
  const calls = [], values = new Map();
  const store = load({ add: async (...args) => { calls.push(args); return cart(1, 777); } }, values);

  assert.equal(await store.getState().addItem(88), true);
  assert.deepEqual(calls, [[88, 1, null]]);
  assert.equal(values.get('cart_session'), token);
  assert.equal(store.getState().items[0].id, 101);
  assert.equal(store.getState().total(), 777, 'The total must not be recomputed from the displayed item price');
});

test('reload restores server cart using the stored guest token', async () => {
  const values = new Map([['cart_session', token]]);
  let received;
  const store = load({ get: async session => { received = session; return cart(3, 750); } }, values);

  assert.equal(await store.getState().loadCart(), true);
  assert.equal(received, token);
  assert.equal(store.getState().items[0].quantity, 3);
});

test('clearing uses one bulk request and keeps the current cart on failure', async () => {
  const calls = [];
  const store = load({
    get: async () => cart(3, 750),
    clear: async session => { calls.push(session); if (calls.length === 1) throw new Error('Offline'); return cart(0, 0); },
  }, new Map([['cart_session', token]]));
  await store.getState().loadCart();
  assert.equal(await store.getState().clearCart(), false);
  assert.equal(store.getState().items[0].quantity, 3);
  assert.equal(await store.getState().clearCart(), true);
  assert.deepEqual(calls, [token, token]);
  assert.deepEqual(store.getState().items, []);
  assert.equal(store.getState().total(), 0);
});

test('queued additions share the server-issued guest session and errors retain the last valid cart', async () => {
  const calls = [], updates = [], values = new Map();
  const store = load({
    add: async (...args) => { calls.push(args); return cart(calls.length); },
    update: async (...args) => { updates.push(args); throw { response: { data: { message: 'الكمية غير متاحة' } } }; },
  }, values);
  await Promise.all([store.getState().addItem(88), store.getState().addItem(88)]);
  assert.equal(calls[0][2], null);
  assert.equal(calls[1][2], token);
  assert.equal(store.getState().items[0].quantity, 2);
  // Stay within the displayed ceiling so stale stock reaches server validation.
  assert.equal(await store.getState().updateQuantity(101, 4), false);
  assert.deepEqual(updates, [[101, 4, token]]);
  assert.equal(store.getState().items[0].quantity, 2);
  assert.equal(store.getState().error, 'الكمية غير متاحة');
  assert.equal(await store.getState().updateQuantity(101, -1), false);
  assert.equal(await store.getState().updateQuantity(101, 999), false);
  assert.equal(updates.length, 1, 'Invalid or over-ceiling quantities must not reach the API');
});

test('a delayed old-session response cannot populate the next account cart', async () => {
  let release;
  const auth = { token: null };
  const store = load({
    add: () => new Promise(resolve => { release = resolve; }),
    get: async () => ({ ...cart(0, 0), customer_id: 9 }),
  }, new Map(), auth);
  const adding = store.getState().addItem(88);
  await new Promise(resolve => setImmediate(resolve));
  auth.token = 'new-account-token';
  const loading = store.getState().loadCart();
  release(cart(1, 999));

  assert.equal(await adding, false);
  assert.equal(await loading, true);
  assert.deepEqual(store.getState().items, []);
  assert.equal(store.getState().total(), 0);
});
