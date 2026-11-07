const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadTs(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = (name) => {
    if (name in dependencies) return dependencies[name];
    if (name === 'react') {
      return {
        useCallback: (fn) => fn,
        useEffect() {},
        useMemo: (fn) => fn(),
        useRef: (value) => ({ current: value }),
        useState: (value) => [typeof value === 'function' ? value() : value, () => {}],
      };
    }
    if (name === 'next/navigation') return { useRouter: () => ({}), useSearchParams: () => ({ get: () => null }) };
    return {};
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}

test('address lines skip empty parts', () => {
  const { buildAddressLine } = loadTs('../apps/storefront/src/components/sophie/checkout/useCheckout.ts');
  assert.equal(buildAddressLine({ street: '  Nile ', building: '', floor: ' 2 ', apartment: '' }), 'Nile, Floor 2');
});

test('idempotency keys do not require crypto.randomUUID', () => {
  const { newIdempotencyKey } = loadTs('../apps/storefront/src/components/sophie/checkout/useCheckout.ts');
  const original = global.crypto;
  global.crypto = {};
  try {
    assert.equal(typeof newIdempotencyKey(), 'string');
    assert.ok(newIdempotencyKey().length > 4);
  } finally {
    global.crypto = original;
  }
});

test('card-only methods leave checkout with nothing it can submit', () => {
  const { checkoutPaymentMethods, shownQuote } = loadTs('../apps/storefront/src/components/sophie/checkout/useCheckout.ts');
  assert.deepEqual(checkoutPaymentMethods(['card']), []);
  assert.deepEqual(checkoutPaymentMethods(['cod', 'card', 'wallet', 'bank_transfer']), ['cod', 'wallet', 'bank_transfer']);
  const quote = { city: 1 };
  assert.equal(shownQuote(quote, 'latest', 'old', false, false), null);
  assert.equal(shownQuote(quote, 'latest', 'latest', true, false), null);
  assert.equal(shownQuote(quote, 'latest', 'latest', false, true), null);
  assert.equal(shownQuote(quote, 'latest', 'latest', false, false), quote);
});

test('shipping rate mapper keeps a null free-shipping threshold and treats a null cod fee as zero', () => {
  const { mapShipRate, mapErrors } = loadTs('../apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts');
  const mapped = mapShipRate({
    id: 1, shipping_company_id: 4, company_name: 'Bosta', shipping_zone_id: 9,
    base_cost: '50.00', free_shipping_threshold: null, cod_fee: null,
    estimated_days_min: 1, estimated_days_max: 3,
  });
  assert.equal(mapped.free, null);
  assert.equal(mapped.cod, 0);
  assert.deepEqual(mapErrors({ base_cost: 'غلط' }, { base_cost: 'base' }), { base: 'غلط' });
});

test('shipping screens do not present fixture companies when the API fails', () => {
  const source = fs.readFileSync(path.resolve(__dirname, '../apps/backoffice/src/pages/admin/operations-mock/commerce.tsx'), 'utf8');
  const shipping = source.slice(source.indexOf('export function ShippingPage'), source.indexOf('export function CompanyModal'));
  assert.equal(shipping.includes('SHIP_COMPANIES'), false);
  assert.equal(shipping.includes('mock:'), false);
  const flags = fs.readFileSync(path.resolve(__dirname, '../apps/backoffice/src/pages/admin/operations-mock/api/source.ts'), 'utf8');
  assert.match(flags, /!import\.meta\.env\.PROD/);
});

function mountCheckout({ endpoints, cart, session, router }) {
  const slots = [];
  let index;
  let effects;
  const params = new URLSearchParams();
  function sameDependencies(previous, dependencies) {
    return previous && dependencies.every((value, i) => Object.is(value, previous.dependencies[i]));
  }
  function useMemo(fn, dependencies) {
    const slot = index++;
    if (!sameDependencies(slots[slot], dependencies)) slots[slot] = { dependencies, value: fn() };
    return slots[slot].value;
  }
  const { useCheckout } = loadTs('../apps/storefront/src/components/sophie/checkout/useCheckout.ts', {
    react: {
      useState(initial) {
        const slot = index++;
        if (!(slot in slots)) slots[slot] = typeof initial === 'function' ? initial() : initial;
        return [slots[slot], (value) => {
          slots[slot] = typeof value === 'function' ? value(slots[slot]) : value;
        }];
      },
      useRef(value) {
        return slots[index++] ??= { current: value };
      },
      useMemo,
      useCallback: (fn, dependencies) => useMemo(() => fn, dependencies),
      useEffect(fn, dependencies) {
        const slot = index++;
        const previous = slots[slot];
        if (sameDependencies(previous, dependencies)) return;
        effects.push(() => {
          previous?.cleanup?.();
          slots[slot] = { dependencies, cleanup: fn() };
        });
      },
    },
    'next/navigation': { useRouter: () => router, useSearchParams: () => params },
    '@alfarid/api-client': { checkoutEndpoints: endpoints },
    '@/stores/cart-store': { getCartSession: session, useCartStore: (select) => select(cart) },
    '@/store/auth.store': { useAuthStore: (select) => select({ user: null, token: null }) },
    '@/hooks/use-auth-hydrated': { useAuthHydrated: () => true },
    '@/lib/viewer-key': { viewerKey: () => 'guest' },
    '@/lib/guest-orders': { rememberGuestOrder() {} },
  });
  return {
    render() {
      index = 0;
      effects = [];
      const view = useCheckout();
      effects.forEach((run) => run());
      return view;
    },
    unmount() {
      slots.forEach((slot) => slot?.cleanup?.());
    },
  };
}

function quote(total, payment_methods = ['cod']) {
  return { options: [{}], preview: { grand_total: total }, payment_methods };
}

function checkoutFixture(t, overrides = {}) {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const storage = new Map();
  const globals = Object.fromEntries(['sessionStorage', 'crypto'].map((key) => [
    key, Object.getOwnPropertyDescriptor(globalThis, key),
  ]));
  Object.defineProperties(globalThis, {
    sessionStorage: { configurable: true, value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
      removeItem: (key) => storage.delete(key),
    } },
    crypto: { configurable: true, value: {} },
  });
  t.after(() => {
    for (const [key, descriptor] of Object.entries(globals)) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor);
      else delete globalThis[key];
    }
  });
  const fixture = {
    cart: { items: [{ quantity: 1, priced: true, available: 5 }], loadCart: async () => {} },
    endpoints: { cities: async () => [], shippingOptions: async () => quote(100), ...overrides },
    session: () => 'cart-session',
    router: { replace() {} },
  };
  return { ...fixture, storage, mount: () => mountCheckout(fixture) };
}

async function advanceQuote(t) {
  t.mock.timers.tick(300);
  await new Promise(setImmediate);
}

test('checkout recovers a lost response after reload, then rotates the key after a 422', async (t) => {
  const attempts = [];
  const fixture = checkoutFixture(t, {
    async place(...args) {
      attempts.push(JSON.parse(JSON.stringify(args)));
      if (attempts.length === 1) throw new Error('Connection dropped before the response');
      if (attempts.length === 2) throw { response: { status: 422 } };
      return { data: { order_number: 'ORD-1' } };
    },
  });
  const first = fixture.mount();
  const { set } = first.render();
  set('fullName', 'Customer');
  set('phone', '01012345678');
  set('cityId', 1);
  set('street', 'Nile');
  set('agreeTerms', true);
  first.render();
  await advanceQuote(t);
  await first.render().submit();
  assert.equal(first.render().uncertain, true);
  assert.equal(JSON.parse(fixture.storage.get('pending_checkout')).key, attempts[0][2]);
  first.unmount();

  const reloaded = fixture.mount();
  reloaded.render();
  const recovered = reloaded.render();
  assert.equal(recovered.uncertain, true);
  assert.equal(recovered.canSubmit, true);
  await recovered.submit();
  assert.deepEqual(attempts[1], attempts[0]);
  assert.equal(reloaded.render().uncertain, false);
  assert.equal(fixture.storage.has('pending_checkout'), false);

  await advanceQuote(t);
  await reloaded.render().submit();
  assert.deepEqual(attempts[2].slice(0, 2), attempts[0].slice(0, 2));
  assert.notEqual(attempts[2][2], attempts[0][2]);
  assert.equal(fixture.storage.has('pending_checkout'), false);
  reloaded.unmount();
});

test('checkout blocks double submission and retries transient HTTP errors with the original request', async (t) => {
  const attempts = [];
  const failures = [408, 409, 429];
  let rejectFirst;
  const fixture = checkoutFixture(t, {
    async place(...args) {
      attempts.push(args);
      const status = failures.shift();
      if (status === 408) return new Promise((resolve, reject) => { rejectFirst = reject; });
      if (status) throw { response: { status } };
      return { data: { order_number: 'ORD-1' } };
    },
  });
  const checkout = fixture.mount();
  const { set } = checkout.render();
  set('fullName', 'Customer');
  set('phone', '01012345678');
  set('cityId', 1);
  set('street', 'Nile');
  set('agreeTerms', true);
  checkout.render();
  await advanceQuote(t);

  const { submit } = checkout.render();
  const inFlight = submit();
  await submit();
  assert.equal(attempts.length, 1);
  rejectFirst({ response: { status: 408 } });
  await inFlight;

  for (const status of [408, 409, 429]) {
    const view = checkout.render();
    assert.equal(view.uncertain, true, `HTTP ${status} leaves the result uncertain`);
    assert.equal(view.locked, true);
    await view.submit();
  }
  assert.equal(attempts.length, 4);
  for (const attempt of attempts.slice(1)) assert.deepEqual(attempt, attempts[0]);
  assert.equal(checkout.render().uncertain, false);
  checkout.unmount();
});

test('checkout ignores stale city responses and blocks submission while quantities change or payment is unavailable', async (t) => {
  const requests = [];
  const fixture = checkoutFixture(t, {
    shippingOptions: ({ city_id }, session, signal) => new Promise((resolve) => {
      requests.push({ cityId: city_id, signal, resolve });
    }),
  });
  const checkout = fixture.mount();
  const { set } = checkout.render();
  for (const cityId of [1, 2, 3]) {
    set('cityId', cityId);
    checkout.render();
    await advanceQuote(t);
  }
  assert.deepEqual(requests.map(({ cityId }) => cityId), [1, 2, 3]);
  assert.deepEqual(requests.map(({ signal }) => signal.aborted), [true, true, false]);
  requests[2].resolve(quote(300));
  await new Promise(setImmediate);
  assert.equal(checkout.render().quote.preview.grand_total, 300);
  assert.equal(checkout.render().canSubmit, true);
  requests[1].resolve(quote(200));
  requests[0].resolve(quote(100));
  await new Promise(setImmediate);
  assert.equal(checkout.render().quote.preview.grand_total, 300);

  fixture.cart.items = [{ ...fixture.cart.items[0], quantity: 2 }];
  checkout.render();
  await advanceQuote(t);
  fixture.cart.busy = true;
  assert.equal(checkout.render().quote, null);
  assert.equal(checkout.render().canSubmit, false);
  requests[3].resolve(quote(600));
  await new Promise(setImmediate);
  assert.equal(checkout.render().quote, null);

  fixture.cart.busy = false;
  checkout.render();
  await advanceQuote(t);
  requests[4].resolve(quote(600, ['card']));
  await new Promise(setImmediate);
  const unsupported = checkout.render();
  assert.equal(unsupported.quote.preview.grand_total, 600);
  assert.equal(unsupported.canSubmit, false);
  checkout.unmount();
});
