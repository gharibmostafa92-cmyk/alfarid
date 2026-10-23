// Live Laravel smoke test. First seed fixtures/inventory-live-setup.php against
// a disposable audit database and serve that same database on INVENTORY_API_URL.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const fixture = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../backend/storage/logs/inventory-live-session.json'), 'utf8'));
const server = process.env.INVENTORY_API_URL || 'http://127.0.0.1:8011';
const frontend = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173/dashboard';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  let context;
  try {
    context = await browser.newContext();
    const errors = [];
    const call = async (method, suffix, data, status = 200, key, token = fixture.adminToken) => {
      const response = await context.request.fetch(server + '/api/v1/admin/' + suffix, {
        method, data, headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', ...(key ? { 'Idempotency-Key': key } : {}) },
      });
      const body = response.status() === 204 ? null : await response.json();
      assert.equal(response.status(), status, method + ' ' + suffix + ': ' + JSON.stringify(body));
      return body;
    };
    const adjust = (type, quantity, status = 201) => call('POST', 'inventory/adjustments', {
      branch_id: fixture.source, location_id: fixture.location,
      items: [{ product_variant_id: fixture.variant, adjustment_type: type, quantity, reason: 'اختبار تكامل حقيقي' }],
    }, status, randomUUID());
    for (const [type, qty] of [['increase', 100], ['mark_damaged', 5], ['restore_damaged', 2], ['write_off_damaged', 3], ['decrease', 2]]) await adjust(type, qty);
    await adjust('decrease', 999999, 422);
    const receipt = { batch_number: 'AUDIT-' + Date.now(), branch_id: fixture.source, location_id: fixture.location, items: [{ product_variant_id: fixture.variant, quantity: 10, unit_cost: 20 }] };
    const key = randomUUID();
    const first = await call('POST', 'production-receipts', receipt, 201, key);
    const second = await call('POST', 'production-receipts', receipt, 201, key);
    assert.equal(first.data.id, second.data.id, 'live production retries must deduplicate');
    await call('POST', 'production-receipts', { ...receipt, batch_number: receipt.batch_number + '-NO-KEY' }, 422);
    let transfer = (await call('POST', 'transfers', { from_branch_id: fixture.source, to_branch_id: fixture.destination, items: [{ product_variant_id: fixture.variant, from_location_id: fixture.location, requested_qty: 10 }] }, 201, randomUUID())).data;
    for (const [stage, field, quantity] of [['approve', 'approved_qty', 10], ['ship', 'shipped_qty', 10], ['receive', 'received_qty', 8]]) {
      if (stage === 'ship') await call('POST', `transfers/${transfer.id}/ship`, { expected_version: transfer.version - 1, items: [{ id: transfer.items[0].id, shipped_qty: 10 }] }, 409);
      transfer = (await call('POST', `transfers/${transfer.id}/${stage}`, { expected_version: transfer.version, items: [{ id: transfer.items[0].id, [field]: quantity }] })).data;
    }
    assert.equal(transfer.status, 'received');
    const count = (await call('POST', 'stocktakes', { branch_id: fixture.source, location_id: fixture.location, items: [{ product_variant_id: fixture.variant, counted_qty: null }] }, 201, randomUUID())).data;
    await call('POST', `stocktakes/${count.id}/approve`, {}, 422);
    await call('PATCH', `stocktakes/${count.id}/items`, { items: [{ product_variant_id: fixture.variant, counted_qty: 0 }] });
    assert.equal((await call('POST', `stocktakes/${count.id}/approve`, {})).data.status, 'approved');

    // Forward browser traffic to the real isolated Laravel server; no fixtures
    // or fabricated responses are used here.
    await context.route('**/api/v1/admin/**', async route => {
      const request = route.request(), url = new URL(request.url());
      try {
        const response = await context.request.fetch(server + url.pathname + url.search, { method: request.method(), data: request.postData() || undefined, headers: { ...request.headers(), host: new URL(server).host } });
        await route.fulfill({ response });
      } catch { await route.abort().catch(() => {}); }
    });
    await context.addInitScript(token => { if (!localStorage.getItem('backoffice_token')) localStorage.setItem('backoffice_token', token); }, fixture.adminToken);
    const page = await context.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(frontend + '#/inventory/adjust');
    const screen = page.locator('[data-inventory-screen]');
    await screen.getByLabel(/الفرع \/ المخزن/).selectOption(String(fixture.source));
    await screen.getByLabel(/موقع التخزين/).selectOption(String(fixture.location));
    const line = screen.getByRole('row').nth(1);
    await line.getByRole('button', { name: /ابحث بالاسم/ }).click();
    await line.getByPlaceholder(/اسم المنتج/).fill('AUDIT-LIVE-001');
    await line.getByRole('button', { name: /AUDIT-LIVE-001/ }).click();
    await line.getByPlaceholder('0.0000').fill('٥٫٥');
    await line.getByPlaceholder(/مثال/).fill('رصيد افتتاحي بأرقام عربية');
    await screen.getByRole('button', { name: 'تنفيذ التسوية', exact: true }).click();
    await page.locator('.modal-in').getByRole('button', { name: 'تنفيذ الآن', exact: true }).click();
    await screen.locator('h1').filter({ hasText: 'حركات المخزون' }).waitFor();
    const levels = await call('GET', `inventory?branch_id=${fixture.source}&product_variant_id=${fixture.variant}`);
    assert.equal(Number(levels.data[0].on_hand), 5.5);
    await page.goto(frontend + '#/production');
    await page.getByText(receipt.batch_number, { exact: true }).waitFor();
    await page.evaluate(token => localStorage.setItem('backoffice_token', token), fixture.keeperToken);
    await page.goto(frontend + '#/inventory/stock');
    await page.reload();
    await screen.getByRole('row', { name: /AUDIT-LIVE-001/ }).waitFor();
    const branch = screen.locator('select').first();
    await branch.locator(`option[value="${fixture.source}"]`).waitFor({ state: 'attached' });
    assert.equal(await branch.isDisabled(), true);
    assert.equal(await branch.inputValue(), String(fixture.source));
    assert.equal(await branch.locator(`option[value="${fixture.destination}"]`).count(), 0);
    const scoped = await call('GET', 'inventory', undefined, 200, undefined, fixture.keeperToken);
    assert.ok(scoped.data.every(row => row.branch.id === fixture.source));
    assert.deepEqual(errors, []);
    console.log('PASS: live Laravel — seeded Super Admin/storekeeper, five adjustments, insufficient stock, receipt retry, transfer lifecycle/version conflict, zero/uncounted count approval, Arabic quantity UI, legacy production, branch restriction.');
  } finally { await context?.unrouteAll({ behavior: 'wait' }); await browser.close(); }
})().catch(error => { console.error(String(error.message).replace(/Bearer\s+\S+/g, 'Bearer [redacted]')); process.exitCode = 1; });
