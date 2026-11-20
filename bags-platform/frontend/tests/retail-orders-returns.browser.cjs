const assert = require('node:assert/strict');
const { mkdir } = require('node:fs/promises');
const { join } = require('node:path');
const { chromium } = require('playwright');

const baseURL = process.env.STOREFRONT_URL || 'http://localhost:3001';
const screenshotDir = process.env.E2E_SCREENSHOT_DIR;
const preview = process.argv.includes('--preview');
const user = { id: 1, name: 'نور الهدى عبد الرحمن', phone: '01000000000' };
const order = {
  id: 1, order_number: 'ORD-1024', channel: 'store', status: 'delivered',
  shipping_address_line: 'شارع النيل، الدور الخامس، أمام المدخل الرئيسي',
  shipping_city: { name_ar: 'القاهرة', name_en: 'Cairo' },
  shipments: [{ id: 1, status: 'delivered' }], grand_total: '1299.99',
  return_quantities: { 1: 2 },
  items: [
    { id: 1, product_name: 'حقيبة سفر جلدية', sku: 'BAG-1', qty: 2 },
    { id: 2, product_name: 'حقيبة كتف', sku: 'BAG-2', qty: 1 },
  ],
};
const worst = {
  ...order,
  grand_total: '12345678.90',
  shipping_address_line: 'العمارة ١٢٨، شقة ١٩، بجوار المدخل الرئيسي لمجمع الخدمات في حي القاهرة الجديدة، البوابة الثالثة على شارع التسعين',
  shipments: [{ id: 2, status: 'out_for_delivery' }, { id: 1, status: 'returned' }],
  items: [
    { ...order.items[0], product_name: 'VARYA-CABIN-ULTRALIGHT-LEATHER-EXPANDABLE-COLLECTION-2026', qty: 99999999 },
    { ...order.items[1], product_name: '', sku: 'BAG-2' },
  ],
};

async function assertFits(page, container) {
  assert.ok(await page.locator(container).first().evaluate(element =>
    element.scrollWidth <= element.clientWidth + 1
  ), `Horizontal overflow in ${container}`);
}

async function main() {
  if (screenshotDir) await mkdir(screenshotDir, { recursive: true });
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge', headless: !preview });
  try {
    const context = await browser.newContext({ baseURL, viewport: { width: 320, height: 760 }, reducedMotion: 'reduce' });
    await context.addInitScript(({ user, language }) => {
      localStorage.setItem('varya_store_lang', language);
      localStorage.setItem('alfarid_web_auth', JSON.stringify({
        version: 0, state: { token: 'test-retail-token', actorType: 'customer', destination: 'retail', home: '/', user },
      }));
    }, { user, language: 'ar' });
    const page = await context.newPage();
    const errors = [];
    const requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/v1/**', async (route) => {
      const { pathname } = new URL(route.request().url());
      const dataMode = new URL(page.url()).searchParams.get('data');
      const activeOrder = dataMode === 'worst' ? worst : order;
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' };
      const send = (json, status = 200) => route.fulfill({ json, status, headers });
      if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
      if (pathname.endsWith('/auth/me')) return send({ data: user, actor: { type: 'customer' }, redirect: { destination: 'retail', path: '/' } });
      if (pathname.endsWith('/branding')) return send({ data: { name: 'VARYA', logo: null } });
      if (pathname.endsWith('/account/orders')) {
        let data = [order, { ...order, id: 2, order_number: 'ORD-1025', status: 'shipped' }];
        if (dataMode === 'empty') data = [];
        if (dataMode === 'one') data = [order];
        if (dataMode === 'worst') data = [worst, {
          ...order, id: 2, order_number: 'ORD-1025', shipping_address_line: null, shipping_city: null, shipments: [], items: [],
        }];
        if (dataMode === 'full') data = Array.from({ length: 15 }, (_, i) => ({ ...order, id: i + 1, order_number: `ORD-${1024 + i}` }));
        return send({ data, meta: { current_page: 1, last_page: 1 } });
      }
      if (pathname.endsWith('/account/orders/ORD-1024')) return send({
        data: dataMode === 'ineligible' ? { ...activeOrder, return_quantities: {} } : activeOrder,
      });
      if (/\/account\/orders\/[^/]+$/.test(pathname)) return send({}, 404);
      if (pathname.endsWith('/account/returns') && route.request().method() === 'POST') {
        const request = route.request().postDataJSON();
        requests.push(request);
        return send({ data: { id: 1, return_number: 'RET-1001' } }, 201);
      }
      if (pathname.endsWith('/account/returns')) return send({
        data: requests.map(request => ({
          id: 1, return_number: 'RET-1001', order_number: order.order_number, status: 'pending',
          reason: request.reason, created_at: '2026-11-19T11:01:00+02:00', total_refund_amount: '0.00',
          items: [{ id: 1, product_name: order.items[0].product_name, requested_qty: 1 }],
        })),
        meta: { current_page: 1, last_page: 1, total: requests.length },
      });
      if (pathname.endsWith('/track')) return send({}, dataMode === 'limited' ? 429 : 404);
      return send({ data: [] });
    });

    if (preview) {
      await page.addInitScript(() => {
        if (location.pathname !== '/account/orders') return;
        const selected = new URLSearchParams(location.search).get('data') || 'demo';
        window.addEventListener('load', () => {
          const menu = document.createElement('nav');
          menu.setAttribute('aria-label', 'Test order data');
          menu.style.cssText = 'position:fixed;z-index:9999;bottom:76px;left:50%;transform:translateX(-50%);display:flex;gap:2px;white-space:nowrap;padding:4px;border:1px solid #aaa;border-radius:10px;background:#e4e4e7;font:12px system-ui;color:#18181b';
          for (const [value, label] of Object.entries({ demo: 'Demo data', worst: 'Worst case', empty: 'Empty', one: 'One', full: 'Full page' })) {
            const link = document.createElement('a');
            link.href = `?data=${value}`;
            link.textContent = label;
            link.style.cssText = 'border-radius:6px;padding:8px 5px';
            if (value === selected) {
              link.setAttribute('aria-current', 'page');
              link.style.background = '#fff';
            }
            menu.append(link);
          }
          document.body.append(menu);
        }, { once: true });
      });
      await page.goto('/account/orders?data=demo');
      await page.waitForEvent('close', { timeout: 0 });
      return;
    }

    for (const width of [320, 375, 740, 1440]) {
      await page.setViewportSize({ width, height: width === 740 ? 375 : 760 });
      await page.goto('/account/orders?data=worst');
      const orderSummary = page.locator('summary').filter({ hasText: 'ORD-1024' });
      await orderSummary.focus();
      await page.keyboard.press('Enter');
      await page.getByText(worst.items[0].product_name, { exact: true }).waitFor();
      await page.getByText('مع مندوب التوصيل').waitFor();
      await assertFits(page, 'details.checkout-block');
      if (screenshotDir) await page.screenshot({ path: join(screenshotDir, `orders-worst-${width}.png`) });
      await page.locator('summary').filter({ hasText: 'ORD-1025' }).click();
      await page.getByText('العنوان غير متاح.').waitFor();
      if (width === 320) {
        await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
        await assertFits(page, 'details.checkout-block');
      }
      if (screenshotDir) {
        await page.goto('/account/orders?data=demo');
        await page.locator('summary').filter({ hasText: 'ORD-1024' }).click();
        await page.screenshot({ path: join(screenshotDir, `orders-demo-${width}.png`) });
      }
    }
    await page.setViewportSize({ width: 320, height: 760 });
    await page.goto('/account/orders?data=empty');
    await page.getByText('لا توجد طلبات بعد.', { exact: false }).waitFor();
    await page.goto('/account/orders?data=one');
    await page.locator('details.checkout-block').first().waitFor();
    assert.equal(await page.locator('details.checkout-block').count(), 1);
    await page.goto('/account/orders?data=full');
    await page.locator('details.checkout-block').nth(14).waitFor();
    assert.equal(await page.locator('details.checkout-block').count(), 15);
    await page.goto('/account/returns/new/ORD-1024?data=ineligible');
    await page.getByText('لا توجد منتجات متاحة للإرجاع في هذا الطلب.').waitFor();
    assert.equal(await page.getByRole('button', { name: 'إرسال طلب الإرجاع' }).count(), 0);

    await page.goto('/account/orders?data=demo');
    await page.locator('summary').filter({ hasText: 'ORD-1024' }).click();
    await page.getByRole('link', { name: 'طلب إرجاع', exact: true }).click();
    await page.getByRole('checkbox', { name: /حقيبة سفر جلدية/ }).check();
    assert.equal(await page.getByRole('checkbox').count(), 1);
    await page.getByLabel('سبب الإرجاع').fill('المقاس غير مناسب');
    await assertFits(page, 'form.checkout-block');
    if (screenshotDir) await page.screenshot({ path: join(screenshotDir, 'return-form-320.png') });
    await page.getByRole('button', { name: 'إرسال طلب الإرجاع' }).click();
    await page.getByText('RET-1001', { exact: true }).waitFor();
    assert.deepEqual(requests, [{ order_id: 1, reason: 'المقاس غير مناسب', items: [{ order_item_id: 1, requested_qty: 1 }] }]);
    await page.getByRole('link', { name: 'متابعة طلبات الإرجاع' }).click();
    await page.locator('summary').filter({ hasText: 'RET-1001' }).click();
    await page.getByText('قيد المراجعة', { exact: true }).waitFor();
    await page.getByText('المقاس غير مناسب', { exact: true }).waitFor();
    await assertFits(page, 'details.checkout-block');

    await page.goto('/orders/ORD-1024/track');
    await page.getByRole('button', { name: 'تتبع الطلب', exact: true }).click();
    await page.getByText('تعذر التحقق من بيانات الطلب.', { exact: false }).waitFor();
    await page.goto('/orders/ORD-1024/track?data=limited');
    await page.getByRole('button', { name: 'تتبع الطلب', exact: true }).click();
    await page.getByText('يرجى الانتظار قليلًا قبل إعادة محاولة تتبع الطلب.').waitFor();
    assert.deepEqual(errors, []);
    console.log('Retail disclosures, return submission and tracking, and public tracking feedback passed.');
  } finally {
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
