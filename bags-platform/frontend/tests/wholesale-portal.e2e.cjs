const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.STOREFRONT_URL || 'http://localhost:3001';
const output = path.resolve(__dirname, '../../../test-results/wholesale-portal');

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    page.setDefaultTimeout(25000);
    const errors = [], submissions = [], downloads = [];
    page.on('pageerror', e => errors.push(e.message));
    let failDashboard = true, failCart = true, forbiddenProducts = false;
    let status = 'approved', unread = 2, price = 300, phase = 'price', reorders = 0;
    const member = () => ({ id: 32, name: 'تاجر الاختبار', company_name: 'شركة الاختبار', phone: '01012345678',
      email: 'wholesale@example.test', type: 'wholesale', status, credit_limit: '250000.00', wallet_balance: '1000.00' });
    const session = () => ({ actor: { type: 'customer', subtype: status === 'approved' ? 'wholesale' : 'wholesale_pending' },
      redirect: { app: 'web', destination: status === 'approved' ? 'wholesale' : 'wholesale_pending',
        path: status === 'approved' ? '/wholesale' : '/wholesale/application-status' }, data: member() });
    const items = [1, 2, 3].map(id => ({ id, product_variant_id: id + 100, product_name: `حقيبة ${id}`, sku: `SKU-${id}`, qty: '2', unit_price: '50.00', line_total: '100.00' }));
    const order = { id: 72, order_number: 'ORD-E2E-72', status: 'delivered', payment_method: 'credit', payment_status: 'unpaid',
      grand_total: '300.00', subtotal: '300.00', discount_amount: '0.00', shipping_cost: '0.00', tax_amount: '0.00',
      returnable: true, cancellable: false, created_at: '2026-11-03T12:00:00Z', items, status_history: [], city_name: 'القاهرة' };
    const cart = () => ({ id: 10, items: items.map(i => ({ ...i, base_price: '60.00', available: true, max_qty: 0, priced: true })),
      totals: { subtotal: '360.00', pricing_discount: '60.00', items_total: '300.00', coupon_discount: '0.00', total: '300.00' } });
    const meta = { current_page: 1, last_page: 1, per_page: 20, total: 1 };
    await context.route('**/api/v1/**', async route => {
      const req = route.request(), url = new URL(req.url()), pathname = url.pathname, method = req.method();
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*',
        'access-control-expose-headers': 'content-disposition' };
      const data = value => route.fulfill({ headers, json: { data: value } });
      const json = value => route.fulfill({ headers, json: value });
      const fail = (status, message, code) => route.fulfill({ status, headers, json: { message, code } });
      if (method === 'OPTIONS') return route.fulfill({ status: 204, headers });
      if (pathname.endsWith('/branding')) return data({ name: 'Sophie', logo: null });
      if (pathname.endsWith('/auth/login')) return json({ ...session(), token: 'wholesale-e2e-token', cart_merged: true });
      if (pathname.endsWith('/auth/me')) return json(session());
      if (pathname.endsWith('/application-status')) return data({ ...member(), rejection_reason: null,
        approved_at: status === 'approved' ? '2026-11-01' : null,
        documents: [{ original_name: 'tax.pdf', document_type: 'tax_card', status: 'verified' }] });
      if (pathname.endsWith('/dashboard')) {
        if (failDashboard) return fail(503, 'Dashboard unavailable');
        return data({ credit_limit: '250000.00', used_credit: '300.00', available_credit: '249700.00',
          in_progress_orders_count: 1, latest_invoices: [order], latest_quotations: [] });
      }
      if (pathname.endsWith('/store/cart/items')) {
        if (failCart) return fail(503, 'Cart unavailable');
        return data(cart());
      }
      if (pathname.endsWith('/account/addresses')) return data([{ id: 2, city_id: 1, city_name_ar: 'القاهرة', address_line: 'شارع الاختبار', is_default: true }]);
      if (pathname.endsWith('/cities')) return data([{ id: 1, name_ar: 'القاهرة' }, { id: 2, name_ar: 'الجيزة' }]);
      if (pathname.endsWith('/shipping-options')) {
        const body = req.postDataJSON();
        await new Promise(resolve => setTimeout(resolve, body.city_id === 2 ? 350 : 20));
        return data({ options: [{ key: 'standard', label: 'شحن قياسي', cost: '0.00' }], payment_methods: ['credit', 'wallet'],
          preview: { items_total: '300.00', coupon_discount: '0.00', shipping: '0.00', cod_fee: '0.00', tax: '0.00', grand_total: String(price) } });
      }
      if (pathname.endsWith('/store/checkout')) {
        submissions.push({ body: req.postDataJSON(), key: req.headers()['idempotency-key'] });
        if (phase === 'price') { phase = 'done'; price = 320; return fail(422, 'Changed price', 'price_changed'); }
        await new Promise(resolve => setTimeout(resolve, 150));
        return data({ ...order, status: 'pending', grand_total: '320.00' });
      }
      if (pathname.endsWith('/notifications/read-all')) { unread = 0; return json({ data: { read_all: true }, meta: { unread_count: unread } }); }
      if (pathname.endsWith('/read')) { unread = Math.max(0, unread - 1); return json({ data: {}, meta: { unread_count: unread } }); }
      if (pathname.endsWith('/notifications')) return json({ data: [1, 2].map(id => ({ id: `notif-${id}`, created_at: '2026-11-03', read_at: null,
        data: { title: `إشعار ${id}`, body: 'تم تحديث الطلب', link: { screen: 'order', id: 72 } } })), meta: { ...meta, total: 2, unread_count: unread } });
      if (pathname.endsWith('/invoices/72/pdf')) {
        downloads.push(req.headers().authorization);
        return route.fulfill({ headers: { ...headers, 'content-type': 'application/pdf', 'content-disposition': 'attachment; filename="invoice-72.pdf"' }, body: '%PDF-1.4\n%%EOF' });
      }
      if (pathname.endsWith('/invoices')) return json({ data: [{ ...order, invoice_number: 'INV-E2E-72' }], meta });
      if (pathname.endsWith('/outstanding')) return data({ outstanding_balance: '300.00' });
      if (pathname.endsWith('/orders/72')) return data(order);
      if (pathname.endsWith('/orders/72/reorder')) {
        reorders++;
        return data({ added: [{ sku: 'SKU-1', qty: 2 }], skipped: [{ sku: 'SKU-2', reason: 'insufficient_stock' }], cart: cart() });
      }
      if (pathname.endsWith('/wholesale/orders')) return json({ data: [order], meta });
      if (pathname.endsWith('/products')) {
        if (forbiddenProducts) return fail(403, 'Approved wholesalers only');
        return json({ data: [], meta: { ...meta, total: 0 } });
      }
      if (pathname.endsWith('/account/returns') && method === 'POST') throw new Error('Invalid images must never reach the server');
      return data([]);
    });
    let loginArtwork;
    const readArtwork = async () => {
      await page.waitForFunction(() => {
        const canvas = document.querySelector('.luxury-right-col canvas');
        if (!canvas) return false;
        const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
        return pixels.some((value, index) => index % 4 === 0 && value < 220 && pixels[index + 3] === 255);
      });
      return page.locator('.luxury-right-col canvas').evaluate(canvas => canvas.toDataURL());
    };
    const login = async () => {
      await page.goto(`${base}/auth/login`);
      loginArtwork = await readArtwork();
      await page.locator('input[name=login]').fill('wholesale@example.test');
      await page.locator('input[name=password]').fill('test-password');
      await page.locator('form button[type=submit]').click();
    };
    const check = async (name, run) => {
      try { await run(); results.push(name); console.log(`PASS ${name}`); }
      catch (error) {
        await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true });
        console.error({ url: page.url(), text: (await page.locator('body').innerText()).slice(0, 1800), errors });
        throw error;
      }
    };

    await login();
    await check('account loading failures offer a working retry', async () => {
      await page.getByRole('button', { name: /إعادة المحاولة/ }).waitFor();
      failDashboard = false;
      await page.getByRole('button', { name: /إعادة المحاولة/ }).click();
      await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
    });
    const nav = name => page.locator('.wp-side').getByText(name, { exact: true }).click();
    await check('cart failure is an error, then retry loads real cart values', async () => {
      await nav('سلة المشتريات');
      await page.getByRole('button', { name: /إعادة المحاولة/ }).waitFor();
      failCart = false;
      await page.getByRole('button', { name: /إعادة المحاولة/ }).click();
      await page.locator('.cart-row').first().waitFor();
      assert.equal(await page.locator('.cart-row').count(), 3);
    });
    await check('notification counts update after single and bulk read', async () => {
      await nav('الإشعارات');
      await page.getByRole('button', { name: 'تحديد كمقروء', exact: true }).first().click();
      await page.waitForFunction(() => document.querySelector('.wp-page-ar')?.textContent.includes('1'));
      await page.getByRole('button', { name: 'تحديد الكل كمقروء', exact: true }).click();
      await page.waitForFunction(() => document.querySelector('.wp-page-ar')?.textContent.includes('0'));
    });
    await check('invoice downloads include Bearer auth and the response filename', async () => {
      await nav('الفواتير');
      await page.getByText('INV-E2E-72', { exact: true }).waitFor();
      const download = page.waitForEvent('download');
      await page.getByRole('button', { name: /PDF/ }).click();
      assert.equal((await download).suggestedFilename(), 'invoice-72.pdf');
      assert.deepEqual(downloads, ['Bearer wholesale-e2e-token']);
    });
    await check('opening reorder once sends one mutation under React StrictMode', async () => {
      await nav('الطلبات');
      await page.getByRole('button', { name: 'إعادة الطلب', exact: true }).click();
      await page.getByText('تم تخطيه · الكمية غير متاحة', { exact: true }).waitFor();
      assert.equal(reorders, 1);
      await page.getByRole('dialog').getByRole('button', { name: 'إغلاق', exact: true }).last().click();
    });
    await check('checkout clears stale previews and retries price changes with the same key', async () => {
      await nav('سلة المشتريات');
      await page.getByRole('button', { name: 'متابعة لإتمام الطلب' }).click();
      const place = page.getByRole('button', { name: 'إتمام الطلب', exact: true });
      await place.waitFor();
      await page.waitForFunction(() => !document.querySelector('.wp-wrap > .grid-2 aside .btn-block')?.disabled);
      await page.locator('select').selectOption('2');
      assert.equal(await place.isDisabled(), true);
      await page.waitForFunction(() => !document.querySelector('.wp-wrap > .grid-2 aside .btn-block')?.disabled);
      await place.click();
      await page.getByText('تغيّر الإجمالي قبل التأكيد.', { exact: true }).waitFor();
      await page.getByRole('button', { name: 'تأكيد الإجمالي الجديد وإتمام الطلب', exact: true }).click();
      await page.getByRole('heading', { name: 'ORDER PLACED', exact: true }).waitFor();
      assert.equal(submissions.length, 2);
      assert.equal(submissions[0].key, submissions[1].key);
      assert.equal(submissions[1].body.expected_total, 320);
      await page.reload();
      await page.getByText('ORD-E2E-72', { exact: true }).waitFor();
      await page.getByRole('button', { name: 'فتح الطلب', exact: true }).click();
      assert.match(page.url(), /screen=order&id=72/);
    });
    await check('eleven return images are rejected before submission', async () => {
      await nav('المرتجعات');
      await page.getByRole('button', { name: /طلب إرجاع/ }).click();
      await page.locator('.ret-item').first().waitFor();
      for (const box of await page.locator('.ret-item input[type=checkbox]').all()) await box.check();
      const file = index => ({ name: `photo-${index}.jpg`, mimeType: 'image/jpeg', buffer: Buffer.from('test') });
      const uploads = page.locator('.ret-item input[type=file]');
      await uploads.nth(0).setInputFiles([1, 2, 3, 4].map(file));
      await uploads.nth(1).setInputFiles([5, 6, 7, 8].map(file));
      await uploads.nth(2).setInputFiles([9, 10, 11].map(file));
      await page.getByText('الحد الأقصى 10 صور إجمالي.', { exact: true }).first().waitFor();
      assert.equal(await page.locator('.photo-chip').count(), 8);
      await page.screenshot({ path: path.join(output, 'return-validation.png'), fullPage: true });
    });
    await check('a 403 from any wholesale screen opens application status', async () => {
      forbiddenProducts = true;
      status = 'suspended';
      await nav('الكتالوج');
      await page.waitForURL('**/wholesale/application-status');
      await page.getByText('موقوف', { exact: true }).waitFor();
    });
    await check('application status preserves the login artwork after refreshing', async () => {
      assert.equal(await readArtwork(), loginArtwork);
      await Promise.all([
        page.waitForResponse(response => response.url().includes('/auth/application-status')),
        page.getByRole('button', { name: 'تحديث حالة الطلب', exact: true }).click(),
      ]);
      await page.getByText('موقوف', { exact: true }).waitFor();
      assert.equal(await readArtwork(), loginArtwork);
      await page.screenshot({ path: path.join(output, 'status-artwork.png'), fullPage: true });
    });
    await check('approval refreshes the stored destination before entering the portal', async () => {
      status = 'approved';
      await page.getByRole('button', { name: 'تحديث حالة الطلب', exact: true }).click();
      await page.getByRole('button', { name: 'الدخول إلى بوابة الجملة', exact: true }).click();
      await page.waitForURL('**/wholesale');
      await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
    });
    await check('a pending trader signing in is directed to the waiting page', async () => {
      await page.locator('.wp-side-foot button').click();
      await page.waitForURL('**/auth/login');
      status = 'pending';
      await login();
      await page.waitForURL('**/wholesale/application-status');
      await page.getByRole('heading', { name: 'حالة طلب الاعتماد', exact: true }).waitFor();
      await page.getByText('قيد المراجعة', { exact: true }).first().waitFor();
      assert.equal(await page.locator('input[name=password]').count(), 0);
    });
    assert.deepEqual(errors, [], 'No browser runtime errors');
    await page.screenshot({ path: path.join(output, 'dashboard.png'), fullPage: true });
    console.log(`${results.length} wholesale browser scenarios passed`);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
