import assert from 'node:assert/strict';
import test from 'node:test';
import { nextCartQuantity } from '../apps/storefront/src/stores/cart-add.ts';
import { utcDateSpanDays } from '../apps/storefront/src/lib/orders/guest-orders.ts';
import { safeStoreHref } from '../apps/storefront/src/lib/core/safe-href.ts';
import { storeImageUrl } from '../apps/storefront/src/lib/catalog/store-image.ts';
import { isAcceptedBusinessDocument } from '../apps/storefront/src/lib/auth/business-document.ts';

test('decrement above available stock clamps to the purchasable quantity', () => {
  assert.equal(nextCartQuantity(4, 2, -1), 2);
  assert.equal(nextCartQuantity(5, 2.9, -1), 2);
  assert.equal(nextCartQuantity(2, 2, -1), 1);
  assert.equal(nextCartQuantity(2, 2, 1), null);
  assert.equal(nextCartQuantity(1, 0, -1), null);
});

test('statement ranges reject a UTC span of 366 days or more', () => {
  assert.equal(utcDateSpanDays('2028-01-01', '2029-01-01'), 366);
  assert.equal(utcDateSpanDays('2028-01-01', '2028-12-31') >= 366, false);
  assert.equal(utcDateSpanDays('2027-01-01', '2028-01-01') >= 366, false);
});

test('banner links reject executable and non-HTTPS external schemes', () => {
  assert.equal(safeStoreHref('javascript:alert(1)'), null);
  assert.equal(safeStoreHref('http://example.com'), null);
  assert.equal(safeStoreHref('/shop/bags'), '/shop/bags');
  assert.equal(safeStoreHref('https://example.com/promo')?.startsWith('https://'), true);
});

test('store media rewrites validate their path and discard query strings', () => {
  assert.equal(storeImageUrl('/storage/products/bag.jpg?token=private'), '/store-media/products/bag.jpg');
  assert.equal(storeImageUrl('/storage/%252e%252e/private.jpg'), '');
  const previous = process.env.NODE_ENV;
  process.env.NODE_ENV = 'production';
  try {
    assert.equal(storeImageUrl('https://images.unsplash.com/photo.jpg'), '');
  } finally {
    if (previous === undefined) delete process.env.NODE_ENV;
    else process.env.NODE_ENV = previous;
  }
});

test('business documents reject empty files and known MIME/extension mismatches', () => {
  assert.equal(isAcceptedBusinessDocument({ name: 'tax.pdf', type: 'application/pdf', size: 1 }), true);
  assert.equal(isAcceptedBusinessDocument({ name: 'tax.pdf', type: 'image/png', size: 1 }), false);
  assert.equal(isAcceptedBusinessDocument({ name: 'tax.pdf', type: '', size: 1 }), true);
  assert.equal(isAcceptedBusinessDocument({ name: 'tax.pdf', type: 'application/pdf', size: 0 }), false);
});
