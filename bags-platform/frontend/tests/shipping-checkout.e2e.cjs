const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.env.STOREFRONT_URL || 'http://127.0.0.1:3001';

async function verifyCheckoutRetry() {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const attempts = [];
    const ordersByKey = new Map();
    const cart = {
      customer_id: null,
      cart_token: 'test-cart-session',
      items: [{ id: 1, product_id: 1, product_variant_id: 1, product_name: 'Test bag',
        qty: 1, unit_price: 100, line_total: 100, available: 5, priced: true }],
      totals: { subtotal: 100, total: 100, coupon_discount: 0, coupon_code: null },
    };
    await page.route('**/api/v1/**', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      const headers = {
        'access-control-allow-origin': '*',
        'access-control-allow-methods': '*',
        'access-control-allow-headers': '*',
      };
      const reply = (data, status = 200) => route.fulfill({ headers, status, json: { data } });
      if (request.method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (path.endsWith('/store/cart/items')) return reply(cart);
      if (path.endsWith('/store/cities')) return reply([{ id: 1, name_ar: 'القاهرة', shipping_cost: 0 }]);
      if (path.endsWith('/shipping-options')) return reply({
        options: [{ key: 'standard', label: 'شحن قياسي', cost: 0 }],
        payment_methods: ['cod'],
        preview: { items_total: 100, pricing_discount: 0, coupon_discount: 0, shipping: 0, cod_fee: 0, tax: 0, grand_total: 100 },
      });
      if (path.endsWith('/store/checkout') && request.method() === 'POST') {
        const key = request.headers()['idempotency-key'];
        attempts.push({ key, session: request.headers()['x-cart-session'], body: request.postDataJSON() });
        if (!ordersByKey.has(key)) ordersByKey.set(key, { order_number: `ORD-TEST-${ordersByKey.size + 1}` });
        if (attempts.length === 1) return route.abort('connectionreset');
        return reply(ordersByKey.get(key), 201);
      }
      return reply([]);
    });

    await page.goto(base + '/checkout');
    await page.locator('#chk-fullname').fill('Checkout tester');
    await page.locator('#chk-phone').fill('01012345678');
    await page.locator('#chk-city').selectOption('1');
    await page.locator('#chk-street').fill('Nile Street');
    await page.locator('input[name="agreeTerms"]').check();
    const submit = page.getByRole('button', { name: 'إتمام الطلب', exact: true });
    const retry = page.getByRole('button', { name: 'إعادة المحاولة', exact: true });
    await submit.click();
    await retry.waitFor();
    assert.equal(attempts.length, 1);
    assert.ok(attempts[0].key);
    assert.equal(attempts[0].session, cart.cart_token);

    await page.reload();
    await retry.click();
    await page.waitForURL('**/checkout/success?order=ORD-TEST-1');
    assert.deepEqual(attempts, [attempts[0], attempts[0]]);
    assert.equal(ordersByKey.size, 1);
    console.log('Checkout browser test passed: connection reset, reload, and retry of the same order.');
  } finally {
    await browser.close();
  }
}

verifyCheckoutRetry().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
