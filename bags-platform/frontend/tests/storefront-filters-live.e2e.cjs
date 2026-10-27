const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.STOREFRONT_URL || 'http://localhost:3000';
const api = process.env.STOREFRONT_API_URL || 'http://127.0.0.1:8000';
const output = path.resolve(__dirname, '../../../test-results/storefront-filters');

(async () => {
  const response = await fetch(`${api}/api/v1/store/catalog/filters`);
  assert.equal(response.status, 200);
  const { data } = await response.json();
  assert.ok(Array.isArray(data.colors) && data.colors.length);
  assert.ok(Array.isArray(data.price_ranges) && data.price_ranges.length);
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  fs.mkdirSync(output, { recursive: true });
  try {
    for (const mobile of [false, true]) {
      const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1440, height: 1000 } });
      const page = await context.newPage(), errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.goto(`${base}/shop`, { waitUntil: 'domcontentloaded' });
      const colors = page.locator('.color-swatches-grid .swatch-btn');
      await page.waitForFunction(count => document.querySelectorAll('.color-swatches-grid .swatch-btn').length === count, data.colors.length + 1);
      assert.equal(await page.locator('[name="priceRange"]').count(), data.price_ranges.length + 1);
      await page.getByRole('button', { name: 'Hide Filters', exact: true }).click();
      await page.getByRole('button', { name: 'Show Filters', exact: true }).click();
      await colors.nth(1).click();
      await page.waitForURL(url => url.searchParams.get('color') === data.colors[0].id);
      const range = data.price_ranges[0];
      await page.locator(`[name="priceRange"][value="${range.id}"]`).locator('..').click();
      await page.waitForURL(url => url.searchParams.get('price_max') === String(range.max));
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => document.querySelectorAll('.color-swatches-grid .swatch-btn').length > 1);
      assert.equal(await page.locator(`[name="priceRange"][value="${range.id}"]`).isChecked(), true);
      assert.equal(await page.locator('.color-swatches-grid .swatch-btn.active').getAttribute('title'), data.colors[0].name_en || data.colors[0].name_ar);
      await page.locator('.color-swatches-grid').scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-filters.png`) });
      assert.deepEqual(errors, []);
      console.log(`${mobile ? '375px mobile' : 'desktop'}: ${data.colors.length} live colors and ${data.price_ranges.length} price ranges visible; toggle, selection and refresh passed`);
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
