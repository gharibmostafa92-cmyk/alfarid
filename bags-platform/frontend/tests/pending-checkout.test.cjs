const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

function load(relative) {
  const filename = path.resolve(__dirname, '..', relative);
  const code = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  new Function('exports', 'module', 'require', code)(mod.exports, mod, require);
  return mod.exports;
}

const {
  cartFingerprint,
  resolveAttemptOwner,
  pendingOwnerMatches,
  shouldDropPendingCheckout,
} = load('apps/storefront/src/components/sophie/checkout/pending-checkout.ts');

test('cart fingerprint includes the coupon and delivery key', () => {
  const items = [{ variantId: 2, quantity: 1 }, { id: 9, quantity: 3 }];
  assert.equal(cartFingerprint(items, 'SAVE10', 'express'), '2:1|9:3#SAVE10#express');
  assert.notEqual(cartFingerprint(items, 'SAVE10', 'express'), cartFingerprint(items, '', 'express'));
  assert.notEqual(cartFingerprint(items, 'SAVE10', 'express'), cartFingerprint(items, 'SAVE10', 'standard'));
  assert.equal(cartFingerprint(items, null, null), cartFingerprint(items, '', ''));
});

test('pending checkout owners match only exactly and fail closed without a user id', () => {
  assert.equal(resolveAttemptOwner(null, null, null), 'guest');
  assert.equal(resolveAttemptOwner('token', 'customer', 8), 'customer:8');
  assert.equal(resolveAttemptOwner('token', 'customer', undefined), null);
  assert.equal(resolveAttemptOwner('token', 'supplier', ''), null);
  assert.equal(pendingOwnerMatches('customer:8', 'customer:8'), true);
  assert.equal(pendingOwnerMatches('customer:8', 'customer:9'), false);
  assert.equal(pendingOwnerMatches('customer:8', 'customer:'), false);
  assert.equal(pendingOwnerMatches('customer:81', 'customer:8'), false);
  assert.equal(pendingOwnerMatches('customer:8', null), false);
  assert.equal(shouldDropPendingCheckout(JSON.stringify({ owner: 'customer:8' }), 'customer:9'), true);
  assert.equal(shouldDropPendingCheckout(JSON.stringify({ owner: 'customer:8' }), 'customer:8'), false);
  assert.equal(shouldDropPendingCheckout(JSON.stringify({ owner: 'customer:8' }), null), true);
  assert.equal(shouldDropPendingCheckout(null, 'guest'), false);
});
