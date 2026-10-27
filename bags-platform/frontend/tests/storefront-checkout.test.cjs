const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function load(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relative), compiled = new Module(filename, module);
  compiled.filename = filename; compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => name in dependencies ? dependencies[name] : Module.prototype.require.call(compiled, name);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}

test('retail return paths cannot escape the site or override restricted destinations', () => {
  const { safeReturnPath } = load('apps/storefront/src/lib/safe-return-path.ts');
  for (const value of [null, '//evil.test', '/\\evil.test', '/%2Fevil.test', '/%5Cevil.test', '/%61uth/login',
    '/checkout/../../auth/login', '/checkout/../../supplier', '/wholesale', '/checkout%0aLocation:evil', 'https://evil.test']) {
    assert.equal(safeReturnPath(value), null, value);
  }
  assert.equal(safeReturnPath('/checkout?promo=SAVE10'), '/checkout?promo=SAVE10');
  assert.equal(safeReturnPath('/shop?category=bags'), '/shop?category=bags');
});

test('checkout endpoints unwrap real Laravel resource envelopes and preserve retry headers', async () => {
  const calls = [], cart = { items: [], totals: { total: 0 } }, preview = { preview: { grand_total: 468 } };
  const client = { defaults: {}, post: async (url, body, config) => {
    calls.push({ url, body, config });
    return { data: url.endsWith('/coupon') ? { data: { cart, coupon_discount: 40 } }
      : url.endsWith('/shipping-options') ? { data: preview } : { data: { order_number: 'ORD-1' } } };
  } };
  const { cartEndpoints, checkoutEndpoints } = load('packages/api-client/src/endpoints/index.ts', {
    './inventory': {}, './operations': {}, '../axios-instance': { defaultApiClient: client, createApiClient: () => client },
  });
  assert.deepEqual(await cartEndpoints.applyCoupon('SAVE10', 'guest-cart'), { cart, coupon_discount: 40 });
  const controller = new AbortController();
  assert.deepEqual(await checkoutEndpoints.shippingOptions({ city_id: 1 }, 'guest-cart', controller.signal), preview);
  assert.equal(calls[1].config.signal, controller.signal);
  await checkoutEndpoints.place({ city_id: 1 }, 'guest-cart', 'same-order-key');
  assert.equal(calls[2].config.headers['Idempotency-Key'], 'same-order-key');
  assert.equal(calls[2].config.headers['X-Cart-Session'], 'guest-cart');
});

test('root categories with direct products remain navigable without fabricated subcategories', () => {
  const { toMegaDepartments } = load('apps/storefront/src/lib/sophie-mappers.ts', {
    './catalog-mappers': { pick: (en, ar) => en || ar },
    './store-routes': load('apps/storefront/src/lib/store-routes.ts'),
  });
  const result = toMegaDepartments([{ slug: 'bags', name_en: 'Bags', products_count: 3, image: null, children: [] },
    { slug: 'empty', name_en: 'Empty', products_count: 0, children: [] }]);
  assert.deepEqual(Object.keys(result), ['bags']);
  assert.equal(result.bags.col1[0].links[0].slug, 'bags');
  assert.equal(result.bags.col1[0].links[0].count, 3);
});

test('a failed login cart merge preserves the original guest session after auth resets cart identity', async () => {
  let session = 'original-guest-cart';
  const calls = [], state = { token: null, setSession: next => { state.token = next.token; session = null; } };
  const { loginUnified } = load('apps/storefront/src/services/auth.service.ts', {
    '@alfarid/api-client': { authEndpoints: { login: async (body, guest) => {
      calls.push(guest);
      return { token: 'customer-token', actor: { type: 'customer', subtype: 'retail' }, redirect: { app: 'web', path: '/checkout' }, data: { id: 8 }, cart_merged: false };
    } } },
    '@/store/auth.store': { useAuthStore: { getState: () => state } },
    '@/stores/cart-store': { getCartSession: () => session, restoreGuestCartSession: value => { session = value; }, clearGuestCartSession: () => { session = null; } },
  });
  assert.equal(await loginUnified('01012345678', 'password', '/checkout'), '/checkout');
  assert.deepEqual(calls, ['original-guest-cart']);
  assert.equal(session, 'original-guest-cart');
  assert.equal(state.token, 'customer-token');
});

test('public API images use a same-origin path across surfaces without proxying unrelated URLs', () => {
  const previous = process.env.NEXT_PUBLIC_API_URL;
  process.env.NEXT_PUBLIC_API_URL = 'http://localhost:8000';
  try {
    const { storeImageUrl } = load('apps/storefront/src/lib/store-image.ts');
    assert.equal(storeImageUrl('http://localhost:8000/storage/product/conversions/thumb.webp'), '/store-media/product/conversions/thumb.webp');
    assert.equal(storeImageUrl('http://127.0.0.1:8000/storage/brand.svg'), '/store-media/brand.svg');
    assert.equal(storeImageUrl('/storage/product.jpg?v=2'), '/store-media/product.jpg?v=2');
    assert.equal(storeImageUrl('https://cdn.example.test/bag.webp'), 'https://cdn.example.test/bag.webp');
    assert.equal(storeImageUrl('/images/fallback.png'), '/images/fallback.png');
    assert.equal(storeImageUrl('http://unrelated.test/storage/private.jpg'), 'http://unrelated.test/storage/private.jpg');
    assert.equal(storeImageUrl('http://localhost:8000/api/v1/store/products'), 'http://localhost:8000/api/v1/store/products');
    assert.equal(storeImageUrl(null), '');
    process.env.NEXT_PUBLIC_API_URL = 'https://api.store.test';
    assert.equal(storeImageUrl('https://api.store.test/storage/banner.png'), '/store-media/banner.png');
  } finally {
    if (previous === undefined) delete process.env.NEXT_PUBLIC_API_URL; else process.env.NEXT_PUBLIC_API_URL = previous;
  }
});
