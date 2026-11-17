import assert from 'node:assert/strict';
import test from 'node:test';
import { lastOrderReceipt, shouldTrackInstapay } from '../apps/storefront/src/components/sophie/checkout/last-order-receipt.ts';

test('persists instapay from the checkout response and skips a later track fetch', () => {
  const instapay = { address: 'shop@instapay', amount: '10.00', expires_at: '2026-01-01T00:00:00Z' };
  const receipt = lastOrderReceipt({
    order_number: 'A-1',
    status: 'pending',
    payment_method: 'bank_transfer',
    payment_status: 'unpaid',
    grand_total: 10,
    items: [],
    recipient_phone: '0100',
    instapay,
  }, 'guest', 1_000);
  assert.deepEqual(receipt.instapay, instapay);

  const without = lastOrderReceipt({
    order_number: 'A-2',
    status: 'pending',
    payment_method: 'cod',
    payment_status: 'unpaid',
    grand_total: 5,
    items: [],
  }, 'customer', 1_000);
  assert.equal(Object.hasOwn(without, 'instapay'), false);
  assert.equal(shouldTrackInstapay({ order_number: 'A-1', payment_method: 'bank_transfer', instapay }, 'A-1', '0100'), false);
  assert.equal(shouldTrackInstapay({ order_number: 'A-2', payment_method: 'bank_transfer' }, 'A-2', '0100'), true);
});
