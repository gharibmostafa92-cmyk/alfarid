const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.env.STOREFRONT_URL || 'http://127.0.0.1:3001';

async function verifyCheckoutRetry() {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const attempts = [];
    const ordersByKey = new Map();
    await page.route('**/receipt-bag-*.svg', route => route.fulfill({
      contentType: 'image/svg+xml',
      body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80"/></svg>',
    }));
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
        if (!ordersByKey.has(key)) ordersByKey.set(key, {
          order_number: `ORD-TEST-${ordersByKey.size + 1}`,
          payment_method: 'cod',
          payment_status: 'unpaid',
          status: 'pending',
          recipient_name: 'Checkout tester',
          grand_total: '100.00',
          items: [
            { id: 1, product_name: 'Private receipt item', image: '/receipt-bag-one.svg', qty: 1, line_total: '60.00' },
            { id: 2, product_name: 'Second receipt item', image: '/receipt-bag-two.svg', qty: 1, line_total: '40.00' },
          ],
        });
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
    await page.locator('details').getByText('Private receipt item', { exact: true }).waitFor();
    await page.getByRole('heading', { name: 'تم استلام طلبك بنجاح!' }).waitFor();
    await page.getByText('قيد المراجعة', { exact: true }).waitFor();
    const photo = page.locator('article figure img');
    await photo.waitFor();
    const chosen = await photo.getAttribute('src');
    assert.ok(['/receipt-bag-one.svg', '/receipt-bag-two.svg'].includes(chosen));
    await page.setViewportSize({ width: 375, height: 812 });
    await page.reload();
    await photo.waitFor();
    assert.equal(await photo.getAttribute('src'), chosen);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    assert.deepEqual(attempts, [attempts[0], attempts[0]]);
    assert.equal(ordersByKey.size, 1);

    await page.getByRole('button', { name: 'Toggle navigation menu' }).click();
    await page.getByRole('link', { name: 'Track Order', exact: true }).click();
    await page.waitForURL('**/orders/track');
    await page.getByRole('link', { name: /ORD-TEST-1/ }).waitFor();
    assert.equal(await page.evaluate(() => localStorage.getItem('alfarid_guest_order_numbers')), '["ORD-TEST-1"]');
    await page.reload();
    await page.getByRole('link', { name: /ORD-TEST-1/ }).click();
    await page.waitForURL('**/orders/ORD-TEST-1/track');

    const freshSession = await browser.newPage();
    await freshSession.goto(base + '/checkout/success?order=ORD-TEST-1');
    await freshSession.getByRole('heading', { name: 'الطلب ORD-TEST-1' }).waitFor();
    assert.equal(await freshSession.getByText('Private receipt item', { exact: true }).count(), 0);
    assert.equal(await freshSession.getByRole('link', { name: 'تتبع طلبك' }).first().getAttribute('href'), '/orders/ORD-TEST-1/track');

    const tracking = await browser.newPage();
    const trackingNumber = 'ORD-%2F/1';
    const trackingPath = '/orders/' + encodeURIComponent(trackingNumber) + '/track';
    await tracking.addInitScript(() => sessionStorage.setItem('pending_checkout', '{invalid json'));
    await tracking.goto(base + trackingPath);
    const orderInput = tracking.locator('#track-order-number');
    const phoneInput = tracking.locator('#track-order-phone');
    await phoneInput.waitFor();
    assert.equal(await phoneInput.inputValue(), '');
    assert.equal(await orderInput.inputValue(), trackingNumber);

    const recentCheckout = await browser.newPage();
    await recentCheckout.addInitScript(() => sessionStorage.setItem('pending_checkout', JSON.stringify({
      body: { recipient_phone: '01012345678' },
    })));
    await recentCheckout.goto(base + trackingPath);
    await recentCheckout.waitForFunction(() => document.querySelector('#track-order-phone')?.value === '01012345678');

    const account = await browser.newPage();
    await account.addInitScript(() => localStorage.setItem('alfarid_web_auth', JSON.stringify({
      state: { token: 'account-test-token', actorType: 'customer', user: { id: 1, phone: '01123456789' } },
      version: 0,
    })));
    const requestedPages = [];
    await account.route('**/api/v1/**', async route => {
      const url = new URL(route.request().url());
      const headers = {
        'access-control-allow-origin': '*',
        'access-control-allow-methods': '*',
        'access-control-allow-headers': '*',
      };
      if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
      if (!url.pathname.endsWith('/store/account/orders')) return route.fulfill({ headers, json: { data: [] } });
      const pageNumber = Number(url.searchParams.get('page'));
      requestedPages.push(pageNumber);
      const ids = pageNumber === 1 ? Array.from({ length: 15 }, (_, index) => index + 1) : [16];
      return route.fulfill({ headers, json: {
        data: ids.map(id => ({
          id, order_number: 'ACCOUNT-' + id, status: 'pending', grand_total: '100.00',
          payment_method: 'cod', payment_status: 'unpaid', items: [],
        })),
        meta: { current_page: pageNumber, last_page: 2 },
      } });
    });
    await account.goto(base + '/account/orders');
    await account.getByRole('heading', { name: 'ACCOUNT-15', exact: true }).waitFor();
    const more = account.getByRole('button', { name: 'عرض المزيد' });
    await more.click();
    await account.getByRole('heading', { name: 'ACCOUNT-16', exact: true }).waitFor();
    await more.waitFor({ state: 'hidden' });
    assert.equal(await account.getByRole('heading', { name: 'ACCOUNT-1', exact: true }).count(), 1);
    assert.deepEqual(requestedPages, [1, 2]);
    await account.goto(base + '/checkout/success?order=ACCOUNT-15');
    await account.getByRole('heading', { name: 'تم استلام طلبك بنجاح!' }).waitFor();
    await account.goto(base + trackingPath);
    await account.waitForFunction(() => document.querySelector('#track-order-phone')?.value === '01123456789');
    console.log('Checkout browser test passed: retry, receipt isolation, account pagination, and tracking phone prefill.');
  } finally {
    await browser.close();
  }
}

verifyCheckoutRetry().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
