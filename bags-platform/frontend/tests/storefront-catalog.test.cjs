const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function load(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => {
    if (name in dependencies) return dependencies[name];
    const sibling = path.resolve(path.dirname(filename), name + '.ts');
    if (name.startsWith('.') && fs.existsSync(sibling)) {
      return load(path.relative(path.resolve(__dirname, '..'), sibling), dependencies);
    }
    return Module.prototype.require.call(compiled, name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}

const currency = load('packages/utils/src/currency.ts');
const mapper = load('apps/storefront/src/lib/catalog-mappers.ts', { '@alfarid/utils': currency });
const query = load('apps/storefront/src/lib/catalog-query.ts');
const { STORE_ROUTES } = load('apps/storefront/src/lib/store-routes.ts');
const { initialProductSelection } = load('apps/storefront/src/lib/product-selection.ts');

test('product stage asks for missing options instead of selecting the first variant', () => {
  const detail = { sizes: ['Medium', 'Large'], colors: [{ key: 'rose' }, { key: 'camel' }], variants: [
    { colorKey: 'rose', sizeLabel: 'Medium', inStock: true, price: 1250 },
    { colorKey: 'rose', sizeLabel: 'Large', inStock: true, price: 1250 },
    { colorKey: 'camel', sizeLabel: 'Medium', inStock: true, price: 1250 },
  ] };
  assert.deepEqual(initialProductSelection(detail), { size: null, color: null });
  assert.deepEqual(initialProductSelection(detail, 'rose'), { size: null, color: 'rose' });
  assert.deepEqual(initialProductSelection(detail, 'CAMEL'), { size: 'Medium', color: 'camel' });
  assert.deepEqual(initialProductSelection(detail, null, 'Large'), { size: 'Large', color: 'rose' });
  assert.deepEqual(initialProductSelection(detail, 'missing'), { size: null, color: null });
});

test('products without sizes still require a color when multiple colors exist', () => {
  const detail = { sizes: [], colors: [{ key: 'noir' }, { key: 'camel' }], variants: [
    { colorKey: 'noir', sizeLabel: null, inStock: true, price: 2150 },
    { colorKey: 'camel', sizeLabel: null, inStock: true, price: 2150 },
  ] };
  assert.deepEqual(initialProductSelection(detail), { size: null, color: null });
  assert.deepEqual(initialProductSelection(detail, 'noir'), { size: null, color: 'noir' });
});

test('all filters round trip through a product link and API parameters preserve array and boolean contracts', () => {
  const state = { category: 'demo-backpacks', color: 'noir', priceMin: 800, priceMax: 1500,
    occasions: ['work', 'casual'], sort: 'price_asc', search: 'a & b', inStock: true };
  const search = query.buildSearch(state, { product: 'bag & leather' });
  assert.equal(new URLSearchParams(search).get('product'), 'bag & leather');
  assert.deepEqual(query.parseFilters(new URLSearchParams(search)), state);
  assert.deepEqual(query.toApiParams(state), { category: 'demo-backpacks', color: 'noir', price_min: 800,
    price_max: 1500, occasions: ['work', 'casual'], search: 'a & b', in_stock: 1, sort: 'price_asc', per_page: 24 });
  assert.deepEqual(query.toFacetParams(state), { category: 'demo-backpacks', occasions: ['work', 'casual'], search: 'a & b', in_stock: 1 });
  assert.equal(STORE_ROUTES.product('bag & leather'), '/shop?product=bag+%26+leather');
  assert.equal(STORE_ROUTES.shop({ sort: 'newest' }), '/shop?sort=newest');
});

test('reset filters produce a clean URL and legacy sort links still parse', () => {
  const empty = query.parseFilters(new URLSearchParams());
  assert.equal(query.buildSearch(empty), '');
  assert.equal(query.parseFilters(new URLSearchParams('sort=price-desc')).sort, 'price_desc');
  assert.equal(query.parseFilters(new URLSearchParams('sort=bad&price_min=nan&occasions=bad,work')).priceMin, null);
  assert.deepEqual(query.parseFilters(new URLSearchParams('occasions=bad,work')).occasions, ['work']);
});

test('cards show real EGP prices and truthful badge precedence, including zero and unavailable prices', () => {
  const card = { id: 1, slug: 'bag', name_en: ' ', name_ar: 'حقيبة', price: '1250', old_price: '1500',
    in_stock: true, discount_percentage: 17, badge_en: 'LUXE', is_new: true, image: '/bag.jpg', colors: [] };
  const vm = mapper.toCardVM(card);
  assert.equal(vm.name, 'حقيبة');
  assert.equal(vm.priceLabel, currency.formatStorePrice(1250));
  assert.equal(vm.badge, '-17%');
  assert.equal(mapper.toCardVM({ ...card, in_stock: false }).badge, 'SOLD OUT');
  assert.equal(mapper.toCardVM({ ...card, discount_percentage: null }).badge, 'LUXE');
  assert.equal(mapper.toCardVM({ ...card, discount_percentage: null, badge_en: null }).badge, 'NEW');
  assert.equal(mapper.toCardVM({ ...card, price: '0' }).price, 0);
  assert.equal(mapper.toCardVM({ ...card, price: null }).priceLabel, 'Unavailable');
});

test('details preserve distinct Arabic color variants, sizes, variant photos and null prices', () => {
  const variant = { id: 10, sku: 'SKU', color_name_en: null, color_name_ar: 'وردي', color_hex: null,
    size: 'Medium', capacity: '14L', price: '1250', old_price: null, in_stock: true, max_qty: 150,
    images: [{ url: '/original.jpg', thumb: '/thumb.jpg' }] };
  const detail = mapper.toDetailVM({ id: 1, slug: 'bag', name_ar: 'حقيبة', image: '/bag.jpg', images: [],
    price: 1250, variants: [variant, { ...variant, id: 11, color_name_ar: 'جملي', price: null }] });
  assert.equal(detail.colors.length, 2);
  assert.deepEqual(detail.sizes, ['Medium (14L)']);
  assert.equal(mapper.resolveVariant(detail, 'جملي', 'Medium (14L)').id, 11);
  assert.equal(mapper.resolveVariant(detail, 'جملي', 'Medium (14L)').price, null);
  assert.equal(detail.variants[0].images[0].url, '/original.jpg');
  assert.equal(detail.images[0].url, '/bag.jpg');
  assert.equal(detail.variants[0].maxQty, 150);
});

test('category lookup finds nested categories and their full breadcrumb path', () => {
  const child = { slug: 'laptop', children: [] };
  const root = { slug: 'backpacks', children: [child] };
  assert.deepEqual(mapper.findCategory([root], 'laptop'), { node: child, path: [root, child] });
  assert.equal(mapper.findCategory([root], 'missing'), null);
});

test('idempotent cart deletes reload the empty cart after a 204 response', async () => {
  const empty = { id: null, cart_token: null, customer_id: null, items: [], totals: { total: 0 } };
  let reads = 0;
  const client = {
    delete: async () => ({ status: 204, data: '' }),
    get: async () => { reads++; return { data: { data: empty } }; },
  };
  const { cartEndpoints } = load('packages/api-client/src/endpoints/index.ts', {
    '../axios-instance': { defaultApiClient: client, createApiClient: () => client }, './inventory': {}, './operations': {},
  });
  assert.deepEqual(await cartEndpoints.clear(null), empty);
  assert.deepEqual(await cartEndpoints.remove(123, null), empty);
  assert.equal(reads, 2);
});

test('production CSP excludes local HTTP images while development previews retain them', () => {
  const saved = { mode: process.env.NODE_ENV, api: process.env.NEXT_PUBLIC_API_URL };
  try {
    process.env.NEXT_PUBLIC_API_URL = 'http://localhost:8000';
    const { middleware } = load('apps/storefront/src/middleware.ts', {
      'next/server': { NextResponse: { next: () => ({ headers: new Headers() }) } },
    });
    const request = { headers: new Headers(), nextUrl: { hostname: 'localhost' } };
    process.env.NODE_ENV = 'production';
    const production = middleware(request).headers.get('Content-Security-Policy').split('; ').find(x => x.startsWith('img-src'));
    assert.ok(!production.includes('http://localhost'));
    assert.ok(!production.includes('http://127.0.0.1'));
    process.env.NODE_ENV = 'development';
    const development = middleware(request).headers.get('Content-Security-Policy').split('; ').find(x => x.startsWith('img-src'));
    assert.ok(development.includes('http://localhost:8000'));
  } finally {
    if (saved.mode === undefined) delete process.env.NODE_ENV; else process.env.NODE_ENV = saved.mode;
    if (saved.api === undefined) delete process.env.NEXT_PUBLIC_API_URL; else process.env.NEXT_PUBLIC_API_URL = saved.api;
  }
});

test('viewer identities are opaque and never reused across account switches', () => {
  const { viewerKey } = load('apps/storefront/src/lib/viewer-key.ts');
  assert.equal(viewerKey(null), 'guest');
  const first = viewerKey('secret-bearer-a');
  assert.equal(viewerKey('secret-bearer-a'), first);
  assert.ok(!first.includes('secret-bearer-a'));
  assert.notEqual(viewerKey('secret-bearer-b'), first);
  assert.equal(viewerKey(null), 'guest');
  assert.notEqual(viewerKey('secret-bearer-a'), first);
});

test('clearing a guest cart session clears both persistent and in-memory fallback', async () => {
  const savedStorage = globalThis.localStorage;
  const storage = new Map();
  globalThis.localStorage = {
    getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key),
  };
  try {
    const sessions = [];
    const cart = { customer_id: null, cart_token: 'guest-session', items: [], totals: { total: 0 } };
    const { useCartStore, clearGuestCartSession, resetCartIdentity } = load('apps/storefront/src/stores/cart-store.ts', {
      '@alfarid/api-client': { getAuthToken: () => null, cartEndpoints: { get: async session => { sessions.push(session); return cart; } } },
      '@/lib/viewer-key': load('apps/storefront/src/lib/viewer-key.ts'),
    });
    await useCartStore.getState().loadCart();
    assert.equal(storage.get('cart_session'), 'guest-session');
    clearGuestCartSession();
    assert.equal(storage.has('cart_session'), false);
    await useCartStore.getState().loadCart();
    assert.deepEqual(sessions, [null, null]);
    useCartStore.setState({ items: [{ id: 1, name: 'Private item' }], serverTotal: 100 });
    resetCartIdentity();
    assert.deepEqual(useCartStore.getState().items, []);
    assert.equal(useCartStore.getState().serverTotal, 0);
  } finally {
    if (savedStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = savedStorage;
  }
});
