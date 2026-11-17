import assert from 'node:assert/strict';
import test from 'node:test';
import { publicObjectImageUrl } from '../apps/storefront/src/lib/catalog/store-image.ts';

test('rewrites only object-storage hosts and drops the query string', () => {
  assert.equal(publicObjectImageUrl('https://evil.example/a.jpg?token=1', ''), '');
  assert.equal(
    publicObjectImageUrl('https://bucket.r2.dev/products/a.jpg?x=1', ''),
    '/store-media/products/a.jpg',
  );
  assert.equal(
    publicObjectImageUrl('https://cdn.example/products/a.jpg?x=1', 'https://cdn.example'),
    'https://cdn.example/products/a.jpg?x=1',
  );
});
