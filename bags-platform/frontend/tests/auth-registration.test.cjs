const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadService(cartMerged) {
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
    };
    if (name === '@/store/auth.store') return { useAuthStore: {
      getState: () => ({ token: null, setSession: value => calls.push(['session', value]) }),
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

test('recovery rejects email locally and sends a phone identifier', async () => {
  const { service, calls } = loadService(true);
  await assert.rejects(service.requestPasswordReset('customer@example.test'));
  assert.equal(calls.length, 0);
  await service.requestPasswordReset(' 01012345678 ');
  assert.deepEqual(calls[0].slice(0, 3), ['post', '/api/v1/store/auth/forgot-password', { phone: '01012345678' }]);
});
