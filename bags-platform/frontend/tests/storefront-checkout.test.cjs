const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function load(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relative), compiled = new Module(filename, module);
  compiled.filename = filename; compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => {
    if (name in dependencies) return dependencies[name];
    const sibling = path.resolve(path.dirname(filename), name + '.ts');
    if (name.startsWith('.') && fs.existsSync(sibling)) {
      return load(path.relative(path.resolve(__dirname, '..'), sibling), dependencies);
    }
    return Module.prototype.require.call(compiled, name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
  }).outputText, filename);
  return compiled.exports;
}

test('public links reject unsafe navigation and encode mail query delimiters', () => {
  const { safeStoreHref, safeMailtoHref, safeTelHref } = load('apps/storefront/src/lib/core/safe-href.ts');
  assert.equal(safeStoreHref('/shop?q=bags'), '/shop?q=bags');
  assert.equal(safeStoreHref('/\t/evil.test'), null);
  assert.equal(safeStoreHref('javascript:alert(1)'), null);
  assert.equal(safeStoreHref('https://shop.test@evil.test'), null);
  assert.equal(safeMailtoHref('sales?team=retail@shop.test'), 'mailto:sales%3Fteam%3Dretail@shop.test');
  assert.equal(safeMailtoHref('sales@shop.test?subject=hello'), null);
  assert.equal(safeMailtoHref('sales\r\n@shop.test'), null);
  assert.equal(safeTelHref('+20 (100) 123-4567'), 'tel:+201001234567');
  assert.equal(safeTelHref('+201001234567?ext=2'), null);
});

test('guest order history expires without extending older orders on a new checkout', (t) => {
  let now = 1_000_000;
  t.mock.method(Date, 'now', () => now);
  const values = new Map();
  const descriptor = Object.getOwnPropertyDescriptor(global, 'localStorage');
  Object.defineProperty(global, 'localStorage', { configurable: true, value: {
    getItem: key => values.get(key),
    setItem: (key, value) => values.set(key, value),
  } });
  t.after(() => descriptor
    ? Object.defineProperty(global, 'localStorage', descriptor)
    : delete global.localStorage);
  const { readGuestOrders, rememberGuestOrder } = load('apps/storefront/src/lib/orders/guest-orders.ts');
  for (let i = 0; i < 12; i++) rememberGuestOrder(`ORD-${i}`);
  assert.equal(readGuestOrders().length, 10);
  now += 24 * 60 * 60_000;
  rememberGuestOrder('ORD-new');
  now += 6 * 24 * 60 * 60_000;
  assert.deepEqual(readGuestOrders(), ['ORD-new']);
});

test('media middleware rejects encoded path separators and traversal before rewriting', () => {
  const { middleware } = load('apps/storefront/src/middleware.ts', {
    'next/server': { NextResponse: class extends Response {
      static next() { return new Response(null, { status: 200 }); }
    } },
  });
  for (const pathname of ['/store-media/%2e%2e/private.pdf', '/store-media/%252e%252e/private.pdf',
    '/store-media/a%2fb.webp', '/store-media/a%5cb.webp', '/store-media/a%00.webp']) {
    assert.equal(middleware({ nextUrl: { pathname } }).status, 404, pathname);
  }
  assert.equal(middleware({ nextUrl: { pathname: '/store-media/bags/a%20b.webp' } }).status, 200);
  assert.equal(middleware({ nextUrl: { pathname: '/store-media/bags/bag-50%25-off.jpg' } }).status, 200);
});

test('relative API urls do not throw and Turnstile stays on script-src', () => {
  const previousApi = process.env.NEXT_PUBLIC_API_URL;
  const previousOffice = process.env.NEXT_PUBLIC_BACKOFFICE_URL;
  process.env.NEXT_PUBLIC_API_URL = '/api';
  process.env.NEXT_PUBLIC_BACKOFFICE_URL = '   ';
  try {
    const { middleware } = load('apps/storefront/src/middleware.ts', {
      'next/server': { NextResponse: class extends Response {
        static next() { return new Response(null, { status: 200 }); }
      } },
    });
    const response = middleware({ nextUrl: { pathname: '/shop', origin: 'https://shop.test', hostname: 'shop.test', searchParams: new URLSearchParams() } });
    const csp = response.headers.get('content-security-policy');
    assert.match(csp, /https:\/\/challenges\.cloudflare\.com/);
    assert.match(csp, /'nonce-/);
    assert.match(csp, /'strict-dynamic'/);
    assert.match(csp, /https:\/\/shop\.test/);
  } finally {
    if (previousApi === undefined) delete process.env.NEXT_PUBLIC_API_URL; else process.env.NEXT_PUBLIC_API_URL = previousApi;
    if (previousOffice === undefined) delete process.env.NEXT_PUBLIC_BACKOFFICE_URL; else process.env.NEXT_PUBLIC_BACKOFFICE_URL = previousOffice;
  }
});

test('InstaPay instructions expire at the deadline even beyond the maximum timer delay', t => {
  const startedAt = Date.UTC(2026, 10, 7);
  const deadline = startedAt + 30 * 24 * 60 * 60 * 1000;
  let now = startedAt;
  let state;
  let effect;
  let timer;
  t.mock.method(Date, 'now', () => now);
  t.mock.method(global, 'setTimeout', (callback, delay) => {
    assert.ok(delay <= 2 ** 31 - 1, 'delay must fit in a browser timer');
    timer = { callback, delay };
    return 1;
  });
  t.mock.method(global, 'clearTimeout', () => { timer = null; });
  const jsx = type => ({ type });
  const { InstaPayInstructions } = load('apps/storefront/src/components/sophie/checkout/InstaPayInstructions.tsx', {
    react: {
      useState(initial) {
        if (!state) state = { value: typeof initial === 'function' ? initial() : initial };
        return [state.value, value => { state.value = value; }];
      },
      useEffect(callback) { effect ??= callback; },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    '@alfarid/utils': { formatStorePrice: String },
  });
  const render = () => InstaPayInstructions({ details: {
    expires_at: new Date(deadline).toISOString(), amount: 100, address: 'test@instapay',
  } });

  assert.equal(render().type, 'section');
  const cleanup = effect();
  const first = timer;
  now += first.delay;
  first.callback();
  assert.equal(render().type, 'section');
  assert.equal(timer.delay, deadline - now);
  now = deadline - 1;
  assert.equal(render().type, 'section');
  now = deadline;
  timer.callback();
  assert.equal(render().type, 'p');
  cleanup();
  assert.equal(timer, null);
});

test('retail return paths cannot escape the site or override restricted destinations', () => {
  const { safeReturnPath } = load('apps/storefront/src/lib/orders/safe-return-path.ts');
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

test('tracking encodes an order number as one path segment and passes the phone separately', async () => {
  const calls = [];
  const payload = { order_number: 'ORD-%2F/1' };
  const client = { defaults: {}, get: async (url, config) => {
    calls.push({ url, config });
    return { data: { data: payload } };
  } };
  const { checkoutEndpoints } = load('packages/api-client/src/endpoints/index.ts', {
    './inventory': {}, './operations': {}, '../axios-instance': { defaultApiClient: client, createApiClient: () => client },
  });
  assert.deepEqual(await checkoutEndpoints.track(payload.order_number, '01012345678'), payload);
  assert.deepEqual(calls, [{
    url: '/api/v1/store/orders/ORD-%252F%2F1/track', config: { params: { phone: '01012345678' } },
  }]);
});

test('root categories with direct products remain navigable without fabricated subcategories', () => {
  const { toMegaDepartments } = load('apps/storefront/src/lib/catalog/sophie-mappers.ts', {
    '@alfarid/utils': load('packages/utils/src/currency.ts'),
    '@/lib/orders/store-routes': load('apps/storefront/src/lib/orders/store-routes.ts'),
  });
  const result = toMegaDepartments([{ slug: 'bags', name_en: 'Bags', products_count: 3, image: null, children: [] },
    { slug: 'empty', name_en: 'Empty', products_count: 0, children: [] }]);
  assert.deepEqual(Object.keys(result), ['bags']);
  assert.equal(result.bags.href, '/categories/bags');
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
    '@/lib/orders/safe-return-path': load('apps/storefront/src/lib/orders/safe-return-path.ts'),
  });
  assert.equal(await loginUnified('01012345678', 'password', '/checkout'), '/checkout');
  assert.deepEqual(calls, ['original-guest-cart']);
  assert.equal(session, 'original-guest-cart');
  assert.equal(state.token, 'customer-token');
});

test('public API images use a same-origin path across surfaces without proxying unrelated URLs', () => {
  const previous = process.env.NEXT_PUBLIC_API_URL;
  const previousCdn = process.env.NEXT_PUBLIC_R2_PUBLIC_URL;
  process.env.NEXT_PUBLIC_API_URL = 'http://localhost:8000';
  process.env.NEXT_PUBLIC_R2_PUBLIC_URL = 'https://cdn.example.test';
  try {
    const { storeImageUrl } = load('apps/storefront/src/lib/catalog/store-image.ts');
    assert.equal(storeImageUrl('http://localhost:8000/storage/product/conversions/thumb.webp'), '/store-media/product/conversions/thumb.webp');
    assert.equal(storeImageUrl('http://127.0.0.1:8000/storage/brand.svg'), '/store-media/brand.svg');
    assert.equal(storeImageUrl('http://192.168.1.10:8000/storage/brand.svg'), '/store-media/brand.svg');
    assert.equal(storeImageUrl('/storage/product.jpg?v=2'), '/store-media/product.jpg?v=2');
    assert.equal(storeImageUrl('https://cdn.example.test/bag.webp'), 'https://cdn.example.test/bag.webp');
    assert.equal(storeImageUrl('/images/fallback.png'), '/images/fallback.png');
    assert.equal(storeImageUrl('http://unrelated.test/storage/private.jpg'), '');
    assert.equal(storeImageUrl('https://unrelated.test/pixel.gif'), '');
    process.env.NEXT_PUBLIC_API_URL = '/api';
    process.env.NEXT_PUBLIC_R2_PUBLIC_URL = '';
    assert.equal(storeImageUrl('http://localhost:8000/storage/product.jpg'), '/store-media/product.jpg');
    assert.equal(storeImageUrl('https://pub.r2.dev/products/bag-50%25-off.jpg'), '/store-media/products/bag-50%25-off.jpg');
    assert.equal(storeImageUrl('https://pub.r2.dev/products/bag.jpg?sig=secret&v=1'), '/store-media/products/bag.jpg');
    assert.equal(storeImageUrl('https://acct.r2.cloudflarestorage.com/media/bag.webp?X-Amz-Signature=xyz'), '/store-media/media/bag.webp');
    assert.equal(storeImageUrl('https://evil.test/tracker.png?token=abc'), '');
    assert.equal(storeImageUrl('https://evil.test/%2e%2e/secret.jpg'), '');
    process.env.NEXT_PUBLIC_R2_PUBLIC_URL = 'https://cdn.example.test';
    process.env.NEXT_PUBLIC_API_URL = 'http://localhost:8000';
    assert.equal(storeImageUrl('http://localhost:8000/api/v1/store/products'), '');
    assert.equal(storeImageUrl(null), '');
    process.env.NEXT_PUBLIC_API_URL = 'https://api.store.test';
    assert.equal(storeImageUrl('https://api.store.test/storage/banner.png'), '/store-media/banner.png');
  } finally {
    if (previous === undefined) delete process.env.NEXT_PUBLIC_API_URL; else process.env.NEXT_PUBLIC_API_URL = previous;
    if (previousCdn === undefined) delete process.env.NEXT_PUBLIC_R2_PUBLIC_URL; else process.env.NEXT_PUBLIC_R2_PUBLIC_URL = previousCdn;
  }
});

test('store image helpers reject unsafe schemes, protocol-relative paths and credentials', () => {
  const { storeImageSource, storeImageUrl } = load('apps/storefront/src/lib/catalog/store-image.ts');
  for (const host of ['cdn.example.test', 'localhost:8000', '127.0.0.1:8000']) {
    for (const input of [
      `https://user:pass@${host}/bag.png`, `http://user@${host}/storage/bag.png`,
      `//${host}/bag.png`, `/\\${host}/bag.png`, `/\t/${host}/bag.png`,
      'javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'data:text/html,test',
    ]) {
      assert.equal(storeImageSource(input), null, input);
      assert.equal(storeImageUrl(input), '', input);
    }
  }
});

test('catalog filters survive a URL round trip with generated valid combinations', () => {
  const { buildSearch, parseFilters } = load('apps/storefront/src/lib/catalog/catalog-query.ts');
  const sorts = ['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name'];
  const occasions = ['casual', 'work', 'weekend', 'evening'];
  for (let sample = 0; sample < 1000; sample++) {
    const state = {
      category: sample % 3 ? 'شنط-يد' : null,
      color: sample % 7 ? 'black' : null,
      priceMin: sample % 5 ? sample / 100 : null,
      priceMax: sample % 7 ? (sample + 500) / 100 : null,
      occasions: occasions.filter((_, index) => (sample >> index) & 1),
      sort: sorts[sample % sorts.length],
      search: sample % 2 ? `شنطة + & # ${sample}` : '',
      inStock: sample % 4 === 0,
      onSale: sample % 3 === 0,
    };
    const search = new URLSearchParams(buildSearch(state, { product: 'شنطة-جلد' }));
    assert.deepEqual(parseFilters(search), state);
    assert.equal(search.get('product'), 'شنطة-جلد');
  }
});
