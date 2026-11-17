import assert from 'node:assert/strict';
import test from 'node:test';
import { hasInitialBranding } from '../apps/storefront/src/lib/core/branding-result.ts';

test('a fallback identity is not treated as a fresh server branding', () => {
  assert.equal(hasInitialBranding(true), false);
  assert.equal(hasInitialBranding(false), true);
});
