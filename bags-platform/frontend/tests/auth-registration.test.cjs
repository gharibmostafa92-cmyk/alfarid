const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function loadService(cartMerged, options = {}) {
  const calls = [];
  const filename = path.resolve(__dirname, '../apps/storefront/src/services/auth.service.ts');
  const loaded = new Module(filename, module);
  loaded.filename = filename;
  loaded.require = name => {
    if (name === '@alfarid/api-client') return {
      defaultApiClient: { post: async (...args) => {
        calls.push(['post', ...args]);
        return { data: { token: 'new-token', data: {}, cart_merged: cartMerged } };
      } },
      authEndpoints: { login: async (...args) => {
        calls.push(['login', ...args]);
        return options.loginResponse ?? {
          token: 'new-token',
          actor: { type: 'customer', subtype: 'retail' },
          redirect: { app: 'storefront', path: '/shop' },
          data: {},
          cart_merged: cartMerged,
        };
      } },
      resolveServiceUrl: value => value,
    };
    if (name === '@/store/auth.store') return { useAuthStore: {
      getState: () => ({ token: null, setSession: value => calls.push(['session', value]), clear: () => calls.push(['authClear']) }),
    } };
    if (name === '@/stores/cart-store') return {
      getCartSession: () => 'guest-session',
      restoreGuestCartSession: value => calls.push(['restore', value]),
      clearGuestCartSession: () => calls.push(['clear']),
    };
    throw new Error(`Unexpected import: ${name}`);
  };
  loaded._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
  }).outputText, filename);
  return { service: loaded.exports, calls };
}

test('retail registration preserves the guest cart when merging failed', async () => {
  const { service, calls } = loadService(false);
  assert.equal(await service.registerWeb({ name: 'Customer', phone: '01012345678', password: 'password123' }, false), '/shop');
  assert.ok(calls.some(call => call[0] === 'session'));
  assert.ok(calls.some(call => call[0] === 'restore' && call[1] === 'guest-session'));
  assert.ok(!calls.some(call => call[0] === 'clear'));
});

test('retail registration clears the guest cart after successful merging', async () => {
  const { service, calls } = loadService(true);
  await service.registerWeb({ name: 'Customer', phone: '01012345678', password: 'password123' }, false);
  assert.ok(calls.some(call => call[0] === 'clear'));
  assert.ok(!calls.some(call => call[0] === 'restore'));
});

test('recovery supports both email and phone identifiers', async () => {
  const { service, calls } = loadService(true);
  await service.requestPasswordReset(' Customer@Example.Test ');
  await service.requestPasswordReset(' 01012345678 ');
  assert.deepEqual(calls[0].slice(0, 3), ['post', '/api/v1/store/auth/forgot-password', { email: 'customer@example.test' }]);
  assert.deepEqual(calls[1].slice(0, 3), ['post', '/api/v1/store/auth/forgot-password', { phone: '01012345678' }]);
});

test('wholesale registration preserves the guest cart session', async () => {
  const { service, calls } = loadService(undefined);
  assert.equal(await service.registerWeb({ name: 'Trader', phone: '01012345678', password: 'password123456' }, true), '/wholesale/application-status');
  assert.ok(calls.some(call => call[0] === 'session'));
  assert.ok(calls.some(call => call[0] === 'restore' && call[1] === 'guest-session'));
  assert.ok(!calls.some(call => call[0] === 'clear'));
});

test('retail registration preserves the guest cart when the server omits cart_merged', async () => {
  const { service, calls } = loadService(undefined);
  assert.equal(await service.registerWeb({ name: 'Customer', phone: '01012345678', password: 'password123' }, false), '/shop');
  assert.ok(calls.some(call => call[0] === 'session'));
  assert.ok(calls.some(call => call[0] === 'restore' && call[1] === 'guest-session'));
  assert.ok(!calls.some(call => call[0] === 'clear'));
});

test('safeLogoutNext allows only a same-origin relative path', () => {
  const { service } = loadService(true);
  assert.equal(service.safeLogoutNext('/account/orders'), '/account/orders');
  assert.equal(service.safeLogoutNext('/'), '/');
  for (const value of ['//evil.test', '/\\evil.test', 'https://evil.test', 'account', '']) {
    assert.equal(service.safeLogoutNext(value), '/auth/login', value);
  }
});

test('loginUnified restores the guest cart before publishing the session', async () => {
  const events = [];
  const filename = path.resolve(__dirname, '../apps/storefront/src/services/auth.service.ts');
  const loaded = new Module(filename, module);
  loaded.filename = filename;
  loaded.require = name => {
    if (name === '@alfarid/api-client') return {
      authEndpoints: { login: async () => ({
        token: 'customer-token', actor: { type: 'customer', subtype: 'retail' },
        redirect: { app: 'web', path: '/shop' }, data: { id: 4 }, cart_merged: false,
      }) },
      resolveServiceUrl: value => value,
    };
    if (name === '@/store/auth.store') return { useAuthStore: { getState: () => ({
      token: null,
      setSession: (_next, options) => {
        events.push('reset');
        if (options?.preserveGuestCart) events.push('restore:' + options.preserveGuestCart);
        events.push('publish');
      },
    }) } };
    if (name === '@/stores/cart-store') return {
      getCartSession: () => 'guest-session',
      restoreGuestCartSession: () => events.push('restore-after'),
      clearGuestCartSession: () => events.push('clear'),
    };
    throw new Error(`Unexpected import: ${name}`);
  };
  loaded._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
  }).outputText, filename);
  assert.equal(await loaded.exports.loginUnified('01012345678', 'secret'), '/shop');
  assert.ok(events.indexOf('restore:guest-session') < events.indexOf('publish'));
  assert.ok(events.indexOf('restore:guest-session') >= 0);
});

test('loginUnified preserves the guest cart when a pending wholesale account is not merged', async () => {
  const actor = { type: 'customer', subtype: 'wholesale_pending' };
  const { service, calls } = loadService(false, {
    loginResponse: {
      token: 'actor-token',
      actor,
      redirect: { app: 'storefront', path: '/wholesale/application-status' },
      data: {},
      cart_merged: false,
    },
  });
  await service.loginUnified('01012345678', 'password123');
  assert.ok(calls.some(call => call[0] === 'restore' && call[1] === 'guest-session'), actor.subtype);
  assert.ok(!calls.some(call => call[0] === 'clear'), actor.subtype);
});
