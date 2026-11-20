const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

// Run against a storefront started with NEXT_PUBLIC_SUPPLIER_PORTAL_ENABLED=true.
test('supplier uploads an invoice with the ASN and reuses the key when retrying', async (t) => {
  const web = process.env.E2E_WEB_URL || 'http://localhost:3001';
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  t.after(() => browser.close());
  const page = await browser.newPage();
  const supplier = { id: 1, name: 'Test supplier' };
  const redirect = { destination: 'supplier', path: '/supplier' };
  const order = {
    id: 42, po_number: 'PO-42', status: 'supplier_accepted', grand_total: '100',
    items: [{ id: 8, product_variant_id: 9, ordered_qty: '10', received_qty: '0' }],
  };
  const submissions = [];

  await page.addInitScript(({ origin, user }) => {
    if (window.location.origin !== origin) return;
    localStorage.setItem('alfarid_web_auth', JSON.stringify({
      state: { token: 'supplier-fixture', actorType: 'supplier', destination: 'supplier', home: '/supplier', user },
    }));
  }, { origin: new URL(web).origin, user: supplier });
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
    const reply = (json, status = 200) => route.fulfill({ json, headers, status });
    if (path.endsWith('/auth/me')) return reply({ actor: { type: 'supplier' }, redirect, data: supplier });
    if (path.endsWith('/supplier/purchase-orders/42')) return reply({ data: order });
    if (path.endsWith('/supplier/purchase-orders/42/deliveries')) {
      submissions.push({
        key: request.headers()['idempotency-key'],
        body: request.postDataBuffer()?.toString(),
      });
      return submissions.length === 1 ? reply({ message: 'Please retry' }, 503) : reply({ data: { id: 43 } }, 201);
    }
    return reply({ data: [] });
  });

  await page.goto(web + '/supplier/purchase-orders/42');
  await page.getByLabel('كمية التوريد').fill('2');
  await page.getByLabel('تاريخ التوريد').fill('2026-11-20');
  const invoice = page.getByLabel('فاتورة المورد (اختياري)');
  await invoice.setInputFiles({ name: 'invoice.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') });
  const submit = page.getByRole('button', { name: 'إرسال التوريد' });
  await submit.click();
  await page.getByRole('alert').getByText(/تعذر تسجيل التوريد/).waitFor();
  await submit.click();
  await page.getByText('تم تسجيل إشعار التوريد.', { exact: true }).waitFor();
  assert.equal(submissions.length, 2);
  assert.ok(submissions[0].key);
  assert.equal(submissions[1].key, submissions[0].key);
  for (const { body } of submissions) {
    assert.match(body, /name="invoice_file"; filename="invoice.pdf"/);
    assert.match(body, /name="items\[0\]\[shipped_qty\]"\r\n\r\n2/);
  }
  assert.equal(await invoice.inputValue(), '');
});
