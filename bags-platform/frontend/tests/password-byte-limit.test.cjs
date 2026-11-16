const { test } = require('node:test');
const assert = require('node:assert/strict');

test('password byte limit matches the backend UTF-8 byte boundary', async () => {
  const { PASSWORD_BYTE_LIMIT, passwordByteLength } = await import('../apps/storefront/src/lib/auth/password-byte-limit.ts');

  assert.equal(passwordByteLength('a'.repeat(PASSWORD_BYTE_LIMIT)), PASSWORD_BYTE_LIMIT);
  assert.equal(passwordByteLength('ع'.repeat(36)), PASSWORD_BYTE_LIMIT);
  assert.ok(passwordByteLength('ع'.repeat(37)) > PASSWORD_BYTE_LIMIT);
  assert.ok(passwordByteLength('a'.repeat(70) + '🙂') > PASSWORD_BYTE_LIMIT);
});
