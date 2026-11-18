import assert from 'node:assert/strict';
import test from 'node:test';
import { publicObjectImageUrl, storeImageUrl } from '../apps/storefront/src/lib/catalog/store-image.ts';

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

test('storage paths drop unsafe segments and unsplash is refused in production', () => {
  const previousEnv = process.env.NODE_ENV;
  const previousApi = process.env.NEXT_PUBLIC_API_URL;
  process.env.NEXT_PUBLIC_API_URL = 'https://api.example';
  try {
    process.env.NODE_ENV = 'development';
    assert.equal(storeImageUrl('https://api.example/storage/products/a.jpg?token=1'), '/store-media/products/a.jpg');
    assert.equal(storeImageUrl('https://api.example/storage/../secret.jpg'), '');
    assert.equal(storeImageUrl('https://images.unsplash.com/photo-1'), 'https://images.unsplash.com/photo-1');
    process.env.NODE_ENV = 'production';
    assert.equal(storeImageUrl('https://images.unsplash.com/photo-1'), '');
    assert.equal(storeImageUrl('https://api.example/storage/products/a.jpg?token=1'), '/store-media/products/a.jpg');
  } finally {
    if (previousEnv === undefined) delete process.env.NODE_ENV;
    else process.env.NODE_ENV = previousEnv;
    if (previousApi === undefined) delete process.env.NEXT_PUBLIC_API_URL;
    else process.env.NEXT_PUBLIC_API_URL = previousApi;
  }
});
