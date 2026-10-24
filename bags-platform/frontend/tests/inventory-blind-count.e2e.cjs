const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const os = require('node:os');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 900 } });
      await context.addInitScript(() => localStorage.setItem('backoffice_token', 'blind-count-test'));
      const count = {
        id: 900, stocktake_number: 'CNT-BLIND-900', branch: { id: 1, name: 'الفرع الرئيسي' },
        status: 'draft', created_by: 1, items: [
          { id: 1, product_variant_id: 7, sku: 'BAG-01', counted_qty: '3.0000' },
          { id: 2, product_variant_id: 8, sku: 'BAG-02', counted_qty: null },
        ],
      };
      let saved;
      await context.route('**/api/v1/admin/**', async route => {
        const request = route.request();
        const endpoint = new URL(request.url()).pathname;
        if (endpoint.endsWith('/me')) return route.fulfill({ json: { data: {
          id: 1, name: 'موظف العد', roles: ['staff'], branch_id: 1, is_active: true,
          permissions: ['inventory.view', 'stocktakes.view', 'stocktakes.update'],
        } } });
        if (endpoint.endsWith('/stocktakes/900/items')) {
          saved = request.postDataJSON();
          for (const item of saved.items) count.items.find(row => row.product_variant_id === item.product_variant_id).counted_qty = String(item.counted_qty);
        }
        if (endpoint.includes('/stocktakes/900')) return route.fulfill({ json: { data: count } });
        return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });
      });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto((process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173/dashboard') + '#/inventory/count?id=900');
      const screen = page.locator('[data-inventory-screen]');
      await screen.getByRole('heading', { name: /CNT-BLIND-900/ }).waitFor();
      const first = screen.getByRole('row').filter({ hasText: 'BAG-01' });
      assert.equal(await first.getByText('تم العدّ', { exact: true }).count(), 1);
      assert.equal(await first.locator('td').nth(2).innerText(), '—');
      await first.getByPlaceholder('لم يُعدّ', { exact: true }).fill('4');
      assert.equal(await first.getByText('تم العدّ', { exact: true }).count(), 1);
      assert.equal(await screen.getByRole('button', { name: 'بفروق', exact: true }).isDisabled(), true);
      assert.equal(await screen.getByRole('button', { name: 'مطابق', exact: true }).isDisabled(), true);
      assert.equal(await screen.getByText('مخفي أثناء العدّ', { exact: true }).count(), 1);
      assert.equal(await screen.getByText('NaN', { exact: true }).count(), 0);
      assert.equal(await first.getByText('عجز', { exact: true }).count(), 0);
      await screen.getByRole('button', { name: 'حفظ العدّ', exact: true }).click();
      await page.getByText('تم حفظ العدّ', { exact: true }).waitFor();
      assert.deepEqual(saved.items, [{ product_variant_id: 7, counted_qty: 4 }]);
      await page.screenshot({ path: path.join(os.tmpdir(), `inventory-blind-${width}.png`), fullPage: true });
      assert.deepEqual(errors, []);
      await context.close();
    }
    console.log('PASS: blind stocktake editing, hidden book quantities, truthful status and saved counts on desktop/mobile.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
