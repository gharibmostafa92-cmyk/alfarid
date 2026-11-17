import assert from 'node:assert/strict';
import test from 'node:test';
import { shouldQueueAdd } from '../apps/storefront/src/stores/cart-add.ts';

test('queues a different variant and rejects a duplicate add already in flight', () => {
  const inFlight = new Set<number>([4]);
  assert.equal(shouldQueueAdd(4, inFlight), false);
  assert.equal(shouldQueueAdd(9, inFlight), true);
});
