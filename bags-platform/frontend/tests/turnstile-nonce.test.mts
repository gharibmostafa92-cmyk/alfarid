import assert from 'node:assert/strict';
import test from 'node:test';
import { turnstileScriptNonce } from '../apps/storefront/src/components/auth/turnstile-nonce.ts';

test('returns a provided CSP nonce and drops an empty one', () => {
  assert.equal(turnstileScriptNonce('abc123'), 'abc123');
  assert.equal(turnstileScriptNonce(''), undefined);
});
