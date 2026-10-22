const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const ts = require('typescript');

const moduleExports = {};
vm.runInNewContext(ts.transpileModule(fs.readFileSync('apps/storefront/src/components/auth/session-nonce.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText, { exports: moduleExports, Uint8Array });
const { createSessionNonce } = moduleExports;

test('uses the browser UUID implementation when available', () => {
  const nonce = createSessionNonce({ randomUUID: () => 'browser-uuid', getRandomValues: () => { throw new Error('Unexpected fallback'); } });
  assert.equal(nonce, 'browser-uuid');
});

test('missing randomUUID uses 128 bits from secure random values', () => {
  const crypto = { getRandomValues: bytes => webcrypto.getRandomValues(bytes) };
  const first = createSessionNonce(crypto), second = createSessionNonce(crypto);
  assert.match(first, /^[a-f0-9]{32}$/);
  assert.match(second, /^[a-f0-9]{32}$/);
  assert.notEqual(first, second);
});

test('missing or failing secure randomness skips discovery without throwing', () => {
  assert.equal(createSessionNonce(), null);
  assert.equal(createSessionNonce({}), null);
  assert.equal(createSessionNonce({ getRandomValues: () => { throw new Error('Not available'); } }), null);
});
