'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadTs(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = (name) => {
    if (name in dependencies) return dependencies[name];
    const sibling = path.resolve(path.dirname(filename), name + '.ts');
    if (name.startsWith('.') && fs.existsSync(sibling)) {
      return loadTs(path.relative(path.resolve(__dirname, '..'), sibling), dependencies);
    }
    return Module.prototype.require.call(compiled, name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}

function readSource(relative) {
  return fs.readFileSync(path.resolve(__dirname, '..', relative), 'utf8');
}

// ---------- F06: POS math, validation, discount clamp, mock detachment ----------
test('F06: POS money helpers reject invalid/non-positive prices and dynamically clamp discounts in both apps', () => {
  for (const rel of ['apps/pos/src/lib/money.ts', 'apps/backoffice/src/pages/pos/lib/money.ts']) {
    const money = loadTs(rel);
    assert.equal(typeof money.parsePositivePrice, 'function', `${rel} must export parsePositivePrice`);
    assert.equal(typeof money.parseNonNegativeAmount, 'function', `${rel} must export parseNonNegativeAmount`);
    assert.equal(typeof money.computeCartTotals, 'function', `${rel} must export computeCartTotals`);

    // Invalid, zero, negative, NaN prices fall back cleanly
    assert.equal(money.parsePositivePrice('0', 250), 250);
    assert.equal(money.parsePositivePrice('-100', 250), 250);
    assert.equal(money.parsePositivePrice('abc', 250), 250);
    assert.equal(money.parsePositivePrice('.', 250), 250);
    assert.equal(money.parsePositivePrice('199.95', 250), 199.95);

    assert.equal(money.parseNonNegativeAmount('-10', 0), 0);
    assert.equal(money.parseNonNegativeAmount('abc', 0), 0);
    assert.equal(money.parseNonNegativeAmount('0', 50), 0);
    assert.equal(money.parseNonNegativeAmount('45.5', 0), 45.5);

    // Float precision in cents: 0.1 + 0.2 = 0.30, never 0.30000000000000004
    const fpTotals = money.computeCartTotals([{ price: 0.1, listPrice: 0.2, qty: 3 }], 0, 100);
    assert.equal(fpTotals.subtotal, 0.6);
    assert.equal(fpTotals.pricedTotal, 0.3);
    assert.equal(fpTotals.lineAdj, 0.3);
    assert.equal(fpTotals.total, 0.3);

    // Dynamic maxDiscountPercent clamp when cart shrinks from 10x1000 (discount 1000 = 10%) to 1x1000
    const clamped = money.computeCartTotals([{ price: 1000, listPrice: 1000, qty: 1 }], 1000, 10);
    assert.equal(clamped.cap, 100);
    assert.equal(clamped.discount, 100);
    assert.equal(clamped.total, 900);

    // Cap respects overridden pricedTotal (e.g. listPrice=1000 overridden down to 400 -> 10% cap = 40, not 100)
    const overriddenCap = money.computeCartTotals([{ price: 400, listPrice: 1000, qty: 1 }], 100, 10);
    assert.equal(overriddenCap.cap, 40);
    assert.equal(overriddenCap.discount, 40);
    assert.equal(overriddenCap.total, 360);
  }
});

test('F06: POS terminal components avoid direct posMockService coupling and cap stock increments', () => {
  for (const appPrefix of ['apps/pos/src', 'apps/backoffice/src/pages/pos']) {
    const cartLineSrc = readSource(`${appPrefix}/components/terminal/CartLine.tsx`);
    assert.equal(cartLineSrc.includes('posMockService'), false, `${appPrefix} CartLine must not import posMockService directly`);
    assert.match(cartLineSrc, /parsePositivePrice/);
    assert.equal(/if\s*\(\s*!p\s*\)\s*return\s+null\s*;/.test(cartLineSrc), false, `${appPrefix} CartLine must render from line metadata without dropping non-mock items`);

    const cartSrc = readSource(`${appPrefix}/components/terminal/Cart.tsx`);
    assert.match(cartSrc, /computeCartTotals/);

    const catalogSrc = readSource(`${appPrefix}/components/terminal/Catalog.tsx`);
    assert.equal(catalogSrc.includes('posMockService'), false, `${appPrefix} Catalog must not use posMockService identifier`);
  }
  for (const file of [
    'apps/pos/src/components/checkout/CheckoutModal.tsx',
    'apps/pos/src/components/checkout/HoldModal.tsx',
    'apps/pos/src/components/checkout/HeldDrawer.tsx',
    'apps/pos/src/components/checkout/ReceiptDone.tsx',
  ]) {
    const src = readSource(file);
    assert.equal(src.includes('posMockService'), false, `${file} must not reference posMockService`);
  }
});

// ---------- F07: useCheckout localStorage TTL + fingerprint + rawForm + unsupported payment banner ----------
function mountCheckoutHook(overrides = {}) {
  const slots = [];
  let index;
  let effects;
  const params = new URLSearchParams();
  const sameDeps = (prev, deps) => prev && deps.every((v, i) => Object.is(v, prev.dependencies[i]));
  function useMemo(fn, deps) {
    const slot = index++;
    if (!sameDeps(slots[slot], deps)) slots[slot] = { dependencies: deps, value: fn() };
    return slots[slot].value;
  }
  const cart = overrides.cart ?? { items: [{ variantId: 11, quantity: 1, price: 100, priced: true, available: 5 }], loadCart: async () => {} };
  const endpoints = { cities: async () => [], shippingOptions: async () => ({ options: [{}], preview: { grand_total: 100 }, payment_methods: ['cod'] }), ...overrides.endpoints };
  const { useCheckout } = loadTs('apps/storefront/src/components/sophie/checkout/useCheckout.ts', {
    react: {
      useState(initial) {
        const slot = index++;
        if (!(slot in slots)) slots[slot] = typeof initial === 'function' ? initial() : initial;
        return [slots[slot], (v) => { slots[slot] = typeof v === 'function' ? v(slots[slot]) : v; }];
      },
      useRef(v) { return slots[index++] ??= { current: v }; },
      useMemo,
      useCallback: (fn, deps) => useMemo(() => fn, deps),
      useEffect(fn, deps) {
        const slot = index++;
        const prev = slots[slot];
        if (sameDeps(prev, deps)) return;
        effects.push(() => { prev?.cleanup?.(); slots[slot] = { dependencies: deps, cleanup: fn() }; });
      },
    },
    'next/navigation': { useRouter: () => ({ replace() {} }), useSearchParams: () => params },
    '@alfarid/api-client': { checkoutEndpoints: endpoints },
    '@/stores/cart-store': { getCartSession: () => 'cart-session', useCartStore: (select) => select(cart) },
    '@/store/auth.store': { useAuthStore: (select) => select({ user: null, token: null }) },
    '@/hooks/use-auth-hydrated': { useAuthHydrated: () => true },
    '@/lib/core/viewer-key': loadTs('apps/storefront/src/lib/core/viewer-key.ts'),
    '@/lib/orders/guest-orders': { rememberGuestOrder() {} },
  });
  return {
    cart,
    render() {
      index = 0;
      effects = [];
      const view = useCheckout();
      effects.forEach((fn) => fn());
      return view;
    },
    unmount() { slots.forEach((s) => s?.cleanup?.()); },
  };
}

test('F07: pending_checkout uses localStorage with 15-min TTL, cart fingerprint, rawForm, and shows unsupported payment banner', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
  const localMap = new Map();
  const sessionMap = new Map();
  const globals = ['localStorage', 'sessionStorage', 'crypto'].map((k) => [k, Object.getOwnPropertyDescriptor(globalThis, k)]);
  Object.defineProperties(globalThis, {
    localStorage: { configurable: true, value: { getItem: (k) => localMap.get(k) ?? null, setItem: (k, v) => localMap.set(k, v), removeItem: (k) => localMap.delete(k) } },
    sessionStorage: { configurable: true, value: { getItem: (k) => sessionMap.get(k) ?? null, setItem: (k, v) => sessionMap.set(k, v), removeItem: (k) => sessionMap.delete(k) } },
    crypto: { configurable: true, value: {} },
  });
  t.after(() => {
    for (const [k, d] of globals) {
      if (d) Object.defineProperty(globalThis, k, d);
      else delete globalThis[k];
    }
  });

  // 1. Saves rawForm + expiresAt in localStorage and avoids address duplication on restore
  const first = mountCheckoutHook({
    endpoints: { place: async () => { throw new Error('network drop'); } },
  });
  const f = first.render();
  f.set('fullName', 'Customer');
  f.set('phone', '01012345678');
  f.set('cityId', 1);
  f.set('street', 'Nile St');
  f.set('building', '5');
  f.set('floor', '2');
  f.set('apartment', '7');
  f.set('agreeTerms', true);
  first.render();
  t.mock.timers.tick(300);
  await new Promise(setImmediate);
  await first.render().submit();

  assert.equal(localMap.has('pending_checkout'), true, 'pending_checkout must be saved in localStorage');
  const saved = JSON.parse(localMap.get('pending_checkout'));
  assert.ok(typeof saved.expiresAt === 'number' && saved.expiresAt > Date.now());
  assert.deepEqual(saved.rawForm, { street: 'Nile St', building: '5', floor: '2', apartment: '7' });
  first.unmount();

  const reloaded = mountCheckoutHook();
  reloaded.render();
  const recovered = reloaded.render();
  assert.equal(recovered.uncertain, true);
  assert.equal(recovered.form.street, 'Nile St');
  assert.equal(recovered.form.building, '5');
  assert.equal(recovered.addressLine, 'Nile St, Bldg 5, Floor 2, Apt 7');
  reloaded.unmount();

  // 2. Expired entry (> 15 min) is discarded on mount
  t.mock.timers.tick(16 * 60 * 1000);
  const afterExpiry = mountCheckoutHook();
  afterExpiry.render();
  assert.equal(afterExpiry.render().uncertain, false);
  assert.equal(localMap.has('pending_checkout'), false);
  afterExpiry.unmount();

  // 3. Unsupported payment methods (e.g. ['card']) emit an explicit banner explaining why submit is disabled
  const unsupportedMount = mountCheckoutHook({
    endpoints: { shippingOptions: async () => ({ options: [{}], preview: { grand_total: 200 }, payment_methods: ['card'] }) },
  });
  unsupportedMount.render().set('cityId', 1);
  unsupportedMount.render();
  t.mock.timers.tick(300);
  await new Promise(setImmediate);
  const unsupportedView = unsupportedMount.render();
  assert.equal(unsupportedView.canSubmit, false);
  assert.ok(unsupportedView.banner && unsupportedView.banner.text.length > 0, 'Must show banner when payment methods are unsupported');
  unsupportedMount.unmount();
});

// ---------- F14: focus throttle + shared authEndpoints.me query + 204 cart responses ----------
test('F14: useCartSync throttles rapid window focus reloads to 15s and authEndpoints.me is shared via React Query', (t) => {
  t.mock.timers.enable({ apis: ['Date'] });
  const listeners = {};
  let loadCalls = 0;
  let effectCleanup;
  const origWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
  Object.defineProperty(globalThis, 'window', {
    configurable: true,
    value: {
      addEventListener: (k, fn) => { (listeners[k] ??= new Set()).add(fn); },
      removeEventListener: (k, fn) => listeners[k]?.delete(fn),
    },
  });
  t.after(() => {
    if (origWindow) Object.defineProperty(globalThis, 'window', origWindow);
    else delete globalThis.window;
  });

  const { useCartSync } = loadTs('apps/storefront/src/hooks/use-cart-sync.ts', {
    react: { useEffect: (fn) => { effectCleanup = fn(); } },
    '@/store/auth.store': { useAuthStore: { persist: { hasHydrated: () => true, onFinishHydration: () => () => {} }, subscribe: () => () => {} } },
    '@/stores/cart-store': { useCartStore: { getState: () => ({ loadCart: async () => { loadCalls++; } }) } },
  });
  useCartSync();
  assert.equal(loadCalls, 1);

  for (const fn of listeners.focus ?? []) fn();
  for (const fn of listeners.focus ?? []) fn();
  assert.equal(loadCalls, 1, 'Focus immediately after mount/reload must be throttled');

  t.mock.timers.tick(15_000);
  for (const fn of listeners.focus ?? []) fn();
  assert.equal(loadCalls, 2, 'Focus after 15s cooldown reloads the cart');
  effectCleanup?.();

  // Verify SophieHeader and RequireActor both use the shared React Query hook instead of direct authEndpoints.me calls
  const headerSrc = readSource('apps/storefront/src/components/sophie/layout/SophieHeader.tsx');
  const requireActorSrc = readSource('apps/storefront/src/components/auth/RequireActor.tsx');
  assert.equal(headerSrc.includes('authEndpoints.me'), false, 'SophieHeader must use shared React Query hook');
  assert.equal(requireActorSrc.includes('authEndpoints.me'), false, 'RequireActor must use shared React Query hook');
});

test('F14: cartEndpoints clear/remove return an empty cart on 204 without firing a follow-up GET', async () => {
  let reads = 0;
  const client = {
    delete: async () => ({ status: 204, data: '' }),
    get: async () => { reads++; return { data: { data: {} } }; },
  };
  const { cartEndpoints } = loadTs('packages/api-client/src/endpoints/index.ts', {
    '../axios-instance': { defaultApiClient: client, createApiClient: () => client },
    './inventory': {},
    './operations': {},
  });
  const cleared = await cartEndpoints.clear(null);
  const removed = await cartEndpoints.remove(123, null);
  assert.equal(reads, 0);
  assert.deepEqual(cleared.items, []);
  assert.equal(cleared.totals.total, 0);
  assert.deepEqual(removed.items, []);
  assert.equal(removed.totals.total, 0);
});

// ---------- F15 & F18: Offers route, Next Link usage, accessible filters & idle cleanup ----------
test('F15 & F18: Offers link targets on_sale, internal nav uses Link, and filter inputs/idle callbacks are accessible & clean', () => {
  const header = readSource('apps/storefront/src/components/sophie/layout/SophieHeader.tsx');
  const mega = readSource('apps/storefront/src/components/sophie/layout/SophieMegaDropdown.jsx');
  const footer = readSource('apps/storefront/src/components/sophie/layout/SophieFooter.tsx');
  const section = readSource('apps/storefront/src/components/sophie/product/SophieProductsSection.tsx');
  const categoriesCss = readSource('apps/storefront/src/styles/categories.css');
  const categoriesTsx = readSource('apps/storefront/src/components/categories/CategoriesContent.tsx');
  const accountMenu = readSource('apps/storefront/src/components/auth/AccountMenu.tsx');

  // F15
  assert.match(header, /STORE_ROUTES\.shop\(\{\s*on_sale:\s*1\s*\}\)/);
  assert.equal(/<a\s+href=\{STORE_ROUTES\.shop/.test(header), false, 'SophieHeader must use Link instead of <a> for STORE_ROUTES');
  assert.match(mega, /from 'next\/link'/);
  assert.match(mega, /STORE_ROUTES\.shop\(\{\s*on_sale:\s*1\s*\}\)/);
  assert.equal(/handleCategoryClick\(e,\s*''\)/.test(mega), false, 'Offers tab must not override URL via empty slug');
  assert.equal(/<a\s+href=\{STORE_ROUTES\.shop/.test(footer), false, 'SophieFooter must use Link for store routes');
  assert.equal(footer.includes('<a href="/wholesale"'), false, 'SophieFooter must use Link for /wholesale');
  assert.match(section, /from 'next\/link'/);

  // F18
  assert.match(header, /cancelIdleCallback/);
  assert.equal(/\.checkbox-label\s+input\s*\{[^}]*display:\s*none/i.test(categoriesCss), false, 'categories.css must not use display: none on checkbox/radio inputs');
  assert.equal(/type="radio"[^>]*readOnly/.test(categoriesTsx), false, 'CategoriesContent radios must use onChange instead of readOnly');
  assert.match(accountMenu, /تسجيل الدخول/);
  assert.match(accountMenu, /تسجيل الخروج/);
});
