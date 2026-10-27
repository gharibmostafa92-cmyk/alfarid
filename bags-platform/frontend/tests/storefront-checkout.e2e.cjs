const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.STOREFRONT_URL || 'http://localhost:3000';
const output = path.resolve(__dirname, '../../../test-results/storefront-checkout');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const category = { id: 1, slug: 'bags', name_en: 'Bags', name_ar: 'حقائب', image: null, products_count: 1, children: [] };
const product = { id: 1, slug: 'everyday', name_en: 'Everyday Bag', name_ar: 'حقيبة', category, image: null,
  price: 200, old_price: null, discount_percentage: null, in_stock: true, default_variant_id: 10, variant_count: 2, colors: [] };
const line = { id: 1, product_id: 1, product_variant_id: 10, product_name: 'Everyday Bag', product_slug: 'everyday',
  product_name_en: 'Everyday Bag', image: null, color: 'Rose', size: 'Medium', sku: 'BAG-10', qty: 2, unit_price: 200,
  line_total: 400, priced: true, available: 5, max_qty: 5 };

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const mobile of [false, true]) {
      const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1440, height: 1000 } });
      const page = await context.newPage();
      page.setDefaultTimeout(20000);
      const errors = [], submissions = [], quotes = [];
      page.on('pageerror', e => errors.push(e.message));
      let cartItems = [{ ...line }], coupon = null, failCities = true, phase = 'field', categoryReads = 0;
      let authed = false, extraCategory = false;
      const member = { id: 8, name: 'Checkout Member', phone: '01012345679', email: 'member@example.test', wallet_balance: '125.00' };
      const receipt = { id: 1, order_number: 'ORD-INTEGRATION', status: 'pending', payment_method: 'cod',
        grand_total: '470.00', items: [{ id: 1, product_name: line.product_name, qty: 2, line_total: '400.00' }] };
      const cart = () => ({ id: 1, customer_id: authed ? 8 : null, cart_token: authed ? null : 'e2e-cart-session', items: cartItems,
        totals: { subtotal: 400, pricing_discount: 0, items_total: 400, coupon_discount: coupon ? 40 : 0, total: coupon ? 360 : 400, coupon_code: coupon } });
      await context.route('**/api/v1/**', async route => {
        const request = route.request(), url = new URL(request.url()), method = request.method();
        const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
        const ok = data => route.fulfill({ headers, json: { data } });
        const fail = (status, json, extra = {}) => route.fulfill({ status, headers: { ...headers, ...extra }, json });
        if (method === 'OPTIONS') return route.fulfill({ status: 204, headers });
        if (url.pathname.endsWith('/branding')) return ok({ name: 'Sophie', logo: null });
        if (url.pathname.endsWith('/auth/me')) return route.fulfill({ headers, json: { data: member, redirect: { path: '/shop', destination: 'retail' } } });
        if (url.pathname.endsWith('/account/addresses')) return ok([{ id: 1, city_id: 2, address_line: 'Saved shipping address', is_default: true }]);
        if (url.pathname.endsWith('/account/orders')) return ok([receipt]);
        if (url.pathname.endsWith('/categories')) { categoryReads++; return ok(extraCategory ? [category, { ...category, id: 2, slug: 'travel', name_en: 'Travel' }] : [category]); }
        if (url.pathname.endsWith('/cities')) {
          if (failCities) { failCities = false; return fail(503, { message: 'Cities offline' }); }
          return ok([{ id: 1, name_ar: 'القاهرة', name_en: 'Cairo' }, { id: 2, name_ar: 'أسوان', name_en: 'Aswan' }]);
        }
        if (url.pathname.endsWith('/cart/items')) {
          if (method === 'POST') cartItems = [{ ...line, qty: 3, line_total: 600 }];
          return ok(cart());
        }
        if (url.pathname.endsWith('/cart/coupon')) {
          if (method === 'POST' && request.postDataJSON().code === 'BAD') return fail(422, { message: 'الكود انتهت صلاحيته' });
          coupon = method === 'DELETE' ? null : request.postDataJSON().code;
          return ok(method === 'DELETE' ? cart() : { cart: cart(), coupon_discount: coupon ? 40 : 0 });
        }
        if (url.pathname.endsWith('/shipping-options')) {
          const body = request.postDataJSON(); quotes.push(body.city_id);
          await sleep(body.city_id === 1 ? 900 : 40);
          const shipping = body.city_id === 1 ? 30 : 70;
          return ok({ city: { id: body.city_id }, cart_total: 400, payment_methods: ['cod'],
            preview: { items_total: 400, coupon_discount: coupon ? 40 : 0, shipping, cod_fee: 0, tax: 0, grand_total: 400 - (coupon ? 40 : 0) + shipping },
            options: [{ key: 'standard', label: 'شحن قياسي', cost: shipping, cod_fee: 0, currency: 'EGP', estimated_days: null }] });
        }
        if (url.pathname.endsWith('/checkout')) {
          submissions.push({ body: request.postDataJSON(), key: request.headers()['idempotency-key'] });
          if (phase === 'cap') return fail(422, { message: 'لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.' });
          if (phase === 'field') { phase = 'price'; return fail(422, { errors: { recipient_phone: ['رقم الهاتف غير مقبول من السيرفر'] } }); }
          if (phase === 'price') { phase = 'rate'; return fail(422, { code: 'price_changed', message: 'Prices changed' }); }
          if (phase === 'rate') { phase = 'network'; return fail(429, { message: 'Rate limited' }, { 'retry-after': '2' }); }
          if (phase === 'network') { phase = 'success'; cartItems = []; return route.abort('connectionfailed'); }
          return route.fulfill({ status: 201, headers, json: { data: receipt, payment_url: null } });
        }
        if (url.pathname.endsWith('/showcase')) return ok([product]);
        if (url.pathname.endsWith('/products')) return route.fulfill({ headers, json: { data: [{ ...product, variant_count: authed ? 1 : 2 }], meta: { current_page: 1, last_page: 1, total: 1 } } });
        if (url.pathname.endsWith('/track')) return ok({ order_number: receipt.order_number, status: 'pending', items: receipt.items, status_history: [] });
        return ok([]);
      });
      await page.goto(`${base}/checkout`, { waitUntil: 'domcontentloaded' });
      await page.getByText('تعذّر تحميل المدن.', { exact: false }).waitFor();
      await page.getByRole('button', { name: 'إعادة المحاولة', exact: true }).click();
      await page.locator('#chk-city option[value="2"]').waitFor({ state: 'attached' });
      await page.locator('#chk-fullname').fill('Integration Shopper');
      await page.locator('#chk-phone').fill('01012345678');
      await page.locator('#chk-street').fill('شارع طويل لاختبار العنوان، عمارة ١٢');
      await page.locator('[name="agreeTerms"]').check();
      await page.locator('#chk-city').selectOption('1');
      await page.waitForRequest(req => req.url().endsWith('/shipping-options') && req.postDataJSON().city_id === 1);
      await page.locator('#chk-city').selectOption('2');
      await page.waitForFunction(() => document.querySelector('#summary-total')?.textContent.includes('470'));
      await sleep(1000);
      assert.match(await page.locator('#summary-shipping').innerText(), /70/);
      assert.match(await page.locator('#summary-total').innerText(), /470/);
      assert.equal(await page.locator('#pay-place-order-btn').isEnabled(), true);
      assert.equal(await page.locator('#profile-trigger-btn').count(), 0);
      assert.equal(await page.locator('a').filter({ hasText: /^Sign in$/ }).count(), 1);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await page.evaluate(() => scrollTo(0, 0));
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-checkout.png`), fullPage: true });

      // Keyboard-opened menu uses the real tree; the product has two sizes, so no unsafe quick-add.
      if (!mobile) {
        await page.locator('nav a[aria-controls="mega-dropdown"]').first().focus();
        await page.keyboard.press('ArrowDown');
        await page.locator('#mega-dropdown.is-open').waitFor();
        await page.getByRole('button', { name: 'View product', exact: true }).waitFor();
        assert.equal(await page.locator('.mega-group-title a').first().getAttribute('href'), '/shop?category=bags');
        await page.keyboard.press('Escape');
        await page.locator('#mega-dropdown:not(.is-open)').waitFor({ state: 'attached' });
        assert.equal(await page.locator('#mega-dropdown').getAttribute('inert'), '');
        await page.locator('nav a[aria-controls="mega-dropdown"]').first().focus(); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Escape');
        assert.equal(categoryReads, 1);
      } else {
        await page.getByRole('button', { name: 'Toggle navigation menu' }).click();
        await page.getByRole('button', { name: 'Bags', exact: false }).click();
        await page.getByRole('link', { name: 'All Bags (1)', exact: true }).waitFor();
        await page.keyboard.press('Escape');
      }
      await page.locator('#promo-code-input').fill('BAD');
      await page.locator('#apply-promo-btn').click();
      await page.getByText('الكود انتهت صلاحيته', { exact: true }).first().waitFor();
      await page.locator('#promo-code-input').fill('SAVE10');
      await page.locator('#apply-promo-btn').click();
      await page.waitForFunction(() => document.querySelector('#summary-total')?.textContent.includes('430'));
      await page.getByRole('button', { name: 'إزالة الكوبون' }).click();
      await page.waitForFunction(() => document.querySelector('#summary-total')?.textContent.includes('470'));
      // A backend field error must appear despite the client-valid phone number.
      await page.locator('#pay-place-order-btn').click();
      await page.getByText('رقم الهاتف غير مقبول من السيرفر', { exact: true }).waitFor();
      await page.locator('#chk-phone').fill('01012345679');
      await page.locator('#pay-place-order-btn').click();
      await page.getByText('الأسعار اتحدثت، راجع الإجمالي واضغط إتمام الطلب تاني.', { exact: true }).waitFor();
      await page.waitForFunction(() => !document.querySelector('#pay-place-order-btn').disabled);
      await page.locator('#pay-place-order-btn').click();
      await page.getByText('تعذّر تأكيد نتيجة الطلب.', { exact: false }).waitFor();
      await page.locator('#pay-place-order-btn').click();
      await page.waitForFunction(() => document.querySelector('#pay-place-order-btn')?.textContent === 'إعادة المحاولة');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.getByText('في محاولة طلب سابقة لم يتم تأكيدها.', { exact: false }).waitFor();
      assert.equal(await page.locator('#chk-phone').isDisabled(), true);
      await page.locator('#pay-place-order-btn').click();
      await page.waitForURL('**/checkout/success?order=ORD-INTEGRATION');
      await page.getByRole('heading', { name: 'تم استلام طلبك!' }).waitFor();
      assert.notEqual(submissions[0].key, submissions[1].key);
      assert.notEqual(submissions[1].key, submissions[2].key);
      assert.equal(submissions[2].key, submissions[3].key);
      assert.equal(submissions[3].key, submissions[4].key);
      assert.deepEqual(submissions[3].body, submissions[4].body);
      assert.equal(submissions[4].body.expected_total, 470);
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.getByRole('heading', { name: 'تم استلام طلبك!' }).waitFor();
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-success.png`), fullPage: true });
      await page.evaluate(() => sessionStorage.removeItem('last_order'));
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.getByRole('link', { name: 'تتبع طلبك' }).click();
      await page.getByPlaceholder('رقم موبايل الطلب').fill('01012345679');
      await page.getByRole('button', { name: 'تتبع الطلب' }).click();
      await page.getByText('pending', { exact: true }).waitFor();
      if (!mobile) {
        // Revalidate expired menu data without reloading the application.
        await page.goto(`${base}/checkout`);
        extraCategory = true;
        await page.evaluate(() => { const now = Date.now.bind(Date); Date.now = () => now() + 600001; document.dispatchEvent(new Event('visibilitychange')); });
        await page.locator('nav a[aria-controls="mega-dropdown"]').first().focus(); await page.keyboard.press('ArrowDown');
        await page.getByRole('tab', { name: 'Travel', exact: true }).waitFor();
        await page.keyboard.press('Escape');
        assert.ok(categoryReads >= 2);
        authed = true; cartItems = [{ ...line }];
        await page.evaluate(user => localStorage.setItem('alfarid_web_auth', JSON.stringify({ state: {
          token: 'e2e-customer-token', actorType: 'customer', destination: 'retail', home: '/shop', user,
        }, version: 0 })), member);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelector('#chk-fullname')?.value === 'Checkout Member');
        assert.equal(await page.locator('#chk-street').inputValue(), 'Saved shipping address');
        await page.locator('#profile-trigger-btn').focus(); await page.keyboard.press('ArrowDown');
        await page.getByRole('menu', { name: 'Profile Menu' }).waitFor();
        assert.match(await page.locator('.profile-identity-wallet').innerText(), /125/);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#profile-trigger-btn').evaluate(el => el === document.activeElement), true);
        await page.locator('nav a[aria-controls="mega-dropdown"]').first().focus(); await page.keyboard.press('ArrowDown');
        await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
        await page.getByRole('dialog', { name: 'Shopping cart' }).waitFor();
        await page.locator('[data-cart-count]').filter({ hasText: /^3$/ }).waitFor();
        await page.getByRole('button', { name: 'Close cart' }).click();
        await page.locator('[name="agreeTerms"]').check();
        await page.waitForFunction(() => !document.querySelector('#pay-place-order-btn').disabled);
        phase = 'cap'; await page.locator('#pay-place-order-btn').click();
        await page.getByText('لديك طلبات معلّقة كثيرة؛ أكملها أو ألغِها قبل طلب جديد.', { exact: true }).waitFor();
        phase = 'success';
        await page.evaluate(() => { const original = Storage.prototype.setItem; Storage.prototype.setItem = function(key, value) {
          if (key === 'last_order') throw new DOMException('Blocked storage', 'SecurityError'); return original.call(this, key, value);
        }; });
        const before = submissions.length;
        await page.evaluate(() => { const button = document.querySelector('#pay-place-order-btn'); button.click(); button.click(); });
        await page.waitForURL('**/checkout/success?order=ORD-INTEGRATION');
        await page.getByRole('heading', { name: 'تم استلام طلبك!' }).waitFor();
        assert.equal(submissions.length, before + 1);
        console.log('authenticated: profile keyboard/wallet, saved address, cache expiry, safe spotlight quick-add, pending cap, double submit, blocked receipt storage passed');
      }
      assert.deepEqual(errors, []);
      console.log(`${mobile ? '375px mobile' : 'desktop'}: checkout, quotes, menu, 422, repricing, 429, lost response, reload replay, success refresh, tracking passed`);
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
