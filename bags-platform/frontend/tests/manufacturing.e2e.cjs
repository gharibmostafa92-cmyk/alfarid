const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const os = require('node:os');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    await context.addInitScript(() => localStorage.setItem('backoffice_token', 'manufacturing-test-only'));
    let permissions = ['*'];
    const receipts = [], dealers = [], requests = [], errors = [];
    const meta = rows => ({ current_page: 1, last_page: 1, per_page: 15, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null });
    await context.route('**/api/v1/admin/**', async route => {
      const req = route.request(), url = new URL(req.url()), p = url.pathname, method = req.method();
      requests.push({ p, method });
      if (p.endsWith('/me')) return route.fulfill({ json: { data: { id: 1, name: 'مدير المصنع', is_active: true, roles: [{ name: 'staff' }], permissions } } });
      if (p.endsWith('/production-receipts/options')) return route.fulfill({ json: { data: { branches: [{ id: 1, name: 'مخزن المصنع', locations: [{ id: 1, name: 'المنتج التام' }] }], variants: [{ id: 7, label: 'شنطة الفريد / BAG-01' }] } } });
      if (p.endsWith('/production-receipts') && method === 'POST') {
        assert.ok(req.headers()['idempotency-key']);
        const data = req.postDataJSON();
        assert.deepEqual(data.items, [{ product_variant_id: 7, quantity: 12, unit_cost: '75' }]);
        receipts.push({ ...data, id: 1, created_at: '2026-10-23T10:00:00Z' });
        return route.fulfill({ status: 201, json: { data: receipts[0] } });
      }
      if (p.endsWith('/production-receipts')) return route.fulfill({ json: { data: receipts, ...meta(receipts) } });
      if (p.endsWith('/customers') && method === 'POST') {
        const data = req.postDataJSON();
        assert.equal(data.type, 'wholesale'); assert.equal(data.status, 'pending');
        dealers.push({ ...data, id: 5, credit_limit: data.credit_limit || '0', documents: [] });
        return route.fulfill({ status: 201, json: { data: dealers[0] } });
      }
      if (p.endsWith('/customers')) {
        assert.equal(url.searchParams.get('business_only'), '1');
        return route.fulfill({ json: { data: dealers, meta: meta(dealers) } });
      }
      if (p.endsWith('/customers/5')) return route.fulfill({ json: { data: dealers[0] } });
      if (p.endsWith('/categories')) return route.fulfill({ json: { data: [] } });
      return route.fulfill({ json: { data: [], meta: meta([]) } });
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on('pageerror', e => errors.push(e.message));
    const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173/dashboard';
    await page.goto(base + '#/production');
    await page.getByLabel('رقم دفعة الإنتاج', { exact: true }).fill('FACTORY-001');
    await page.getByText('اختر الفرع', { exact: true }).click();
    await page.getByRole('button', { name: 'مخزن المصنع', exact: true }).click();
    await page.getByText('اختر الصنف (أول 50 نتيجة)', { exact: true }).click();
    await page.getByRole('button', { name: 'شنطة الفريد / BAG-01', exact: true }).click();
    await page.getByRole('button', { name: 'إضافة الصنف', exact: true }).click();
    await page.getByLabel('الكمية', { exact: true }).fill('12');
    await page.getByLabel('تكلفة تصنيع الوحدة', { exact: true }).fill('75');
    await page.screenshot({ path: path.join(os.tmpdir(), 'manufacturing-production-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.getByRole('button', { name: 'تأكيد استلام الإنتاج', exact: true }).click();
    await page.getByRole('cell', { name: 'FACTORY-001', exact: true }).waitFor();
    assert.equal(receipts.length, 1);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(os.tmpdir(), 'manufacturing-production-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.goto(base + '#/dealers');
    await page.getByRole('button', { name: 'إضافة تاجر', exact: true }).click();
    await page.getByLabel('اسم التاجر', { exact: true }).fill('أحمد تاجر الجملة');
    await page.getByLabel('رقم التليفون', { exact: true }).fill('01012345678');
    await page.getByLabel('كلمة المرور', { exact: true }).fill('dealer-password');
    await page.getByLabel('اسم الشركة أو المحل', { exact: true }).fill('تجارة الحقائب');
    await page.getByRole('button', { name: 'حفظ البيانات', exact: true }).click();
    await page.getByRole('cell', { name: 'أحمد تاجر الجملة تجارة الحقائب', exact: true }).waitFor();
    await page.screenshot({ path: path.join(os.tmpdir(), 'manufacturing-dealers-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.screenshot({ path: path.join(os.tmpdir(), 'manufacturing-dealers-desktop.png'), fullPage: true, animations: 'disabled' });
    assert.ok(!requests.some(r => /brands|suppliers|purchase-orders/.test(r.p)));
    permissions = ['inventory.view'];
    await page.goto(base + '#/production'); await page.reload();
    await page.getByRole('heading', { name: 'استلام الإنتاج', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'تأكيد استلام الإنتاج', exact: true }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: production receipt, dealer creation, read-only permissions, desktop/mobile, no resale requests or runtime errors');
    console.log('Screenshots: ' + os.tmpdir());
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
