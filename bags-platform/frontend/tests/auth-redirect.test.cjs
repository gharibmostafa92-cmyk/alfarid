const { test } = require('node:test');
const assert = require('node:assert/strict');

test('expired sessions return protected pages to login while public guest routes stay available', async () => {
  const { unauthorizedLoginRedirect } = await import('../apps/storefront/src/store/auth-redirect.ts');

  assert.equal(
    unauthorizedLoginRedirect('/account/orders', '?page=2', true),
    '/auth/login?redirect=%2Faccount%2Forders%3Fpage%3D2',
  );
  assert.equal(unauthorizedLoginRedirect('/orders/track', '?order=123', true), null);
  assert.equal(unauthorizedLoginRedirect('/checkout', '?coupon=SAVE', false), null);
  assert.equal(
    unauthorizedLoginRedirect('/checkout', '?coupon=SAVE', true),
    '/auth/login?redirect=%2Fcheckout%3Fcoupon%3DSAVE',
  );
  assert.equal(unauthorizedLoginRedirect('/auth/login', '', true), null);
});
