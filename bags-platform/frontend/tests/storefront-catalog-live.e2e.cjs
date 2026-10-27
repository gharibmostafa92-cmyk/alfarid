const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const base = process.env.STOREFRONT_URL || 'http://localhost:3000';
const api = process.env.STOREFRONT_API_URL || 'http://127.0.0.1:8000';
const output = path.resolve(__dirname, '../../../test-results/storefront-catalog');

(async () => {
  const facets = await fetch(`${api}/api/v1/store/catalog/filters`).then(r => r.json());
  assert.equal(facets.data.total, 12);
  assert.equal(facets.data.colors.length, 5);
  assert.deepEqual(facets.data.price_ranges.map(r => r.count), [2, 7, 3]);
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  fs.mkdirSync(output, { recursive: true });
  try {
    for (const mobile of [false, true]) {
      const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 } });
      const page = await context.newPage();
      page.setDefaultTimeout(20000);
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      try {
        await page.goto(`${base}/shop`, { waitUntil: 'domcontentloaded' });
        await page.locator('.filter-count-badge').filter({ hasText: 'Showing 12 pieces' }).waitFor();
        assert.equal(await page.locator('.product-card:not(.skeleton)').count(), 12);
        await page.getByRole('button', { name: 'Backpacks', exact: true }).click();
        await page.locator('.filter-count-badge').filter({ hasText: 'Showing 5 pieces' }).waitFor();
        await page.evaluate(() => { window.__headerNode = document.querySelector('[data-store-header]'); });
        await page.getByText("Vogue Elegance Women's Backpack", { exact: true }).click();
        await page.locator('.product-heading h1').waitFor();
        assert.equal(await page.evaluate(() => window.__headerNode === document.querySelector('[data-store-header]')), true);
        assert.equal(await page.locator('.cat-hero-banner').count(), 0);
        assert.equal(await page.getByRole('region', { name: 'Interactive product stage' }).count(), 1);
        if (!mobile) {
          const options = await page.locator('.product-configurator').boundingBox();
          const stage = await page.locator('.product-visual').boundingBox();
          assert.ok(options.x < 100 && stage.width > 1300);
        }
        assert.equal(await page.locator('.design-bag-slot').count(), 3);
        assert.equal(await page.locator('.product-selection-prompt').textContent(), 'Choose your size');
        await page.getByRole('button', { name: 'Medium (14L)', exact: true }).click();
        assert.equal(await page.locator('.product-selection-prompt').textContent(), 'Choose your color');
        await page.getByRole('button', { name: 'Rose', exact: true }).click();
        await page.locator('.product-stage-image').evaluate(img => img.decode());
        const roseImage = await page.locator('.product-stage-image').getAttribute('src');
        await page.locator('.product-color-options button[aria-label="Camel"]').click();
        await page.locator('.product-stage-image').evaluate(img => img.decode());
        assert.notEqual(await page.locator('.product-stage-image').getAttribute('src'), roseImage);
        assert.equal(await page.getByRole('button', { name: 'Large (20L)', exact: true }).isEnabled(), true);
        await page.getByRole('button', { name: 'Increase quantity' }).click();
        await page.locator('.product-add-button').click();
        await page.locator('[data-cart-count]').filter({ hasText: /^2$/ }).waitFor();
        const selectedPhoto = await page.locator('.product-stage-image').getAttribute('src');
        await page.mouse.wheel(0, 750);
        await page.waitForFunction(() => window.scrollY > 200);
        assert.equal(await page.locator('.product-stage-image').getAttribute('src'), selectedPhoto);
        await page.locator('#product-details').scrollIntoViewIfNeeded();
        const headerBounds = await page.locator('[data-store-header]').boundingBox();
        assert.equal(Math.round(headerBounds.y), 0);
        await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
        await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-live-detail.png`), fullPage: true });
        await page.goto(`${base}/design`);
        await page.getByRole('button', { name: 'EXPLORE DETAILS' }).waitFor();
        await page.locator('[data-cart-count]').filter({ hasText: /^2$/ }).waitFor();
        assert.equal(await page.locator('.design-carousel-arrow').count(), 2);
        await page.locator('.design-bag-slot.center img').evaluate(img => img.decode());
        await page.getByRole('button', { name: 'EXPLORE DETAILS' }).click();
        await page.locator('.product-selection-prompt').waitFor();
        assert.equal(await page.locator('.cat-hero-banner').count(), 0);
        assert.equal(await page.locator('[data-store-header]').count(), 1);
        if (mobile) {
          const bounds = await page.locator('.product-configurator').boundingBox();
          assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390);
        }
        await page.goto(base + '/shop?category=demo-handbags&product=demo-urban-chic-laptop-backpack');
        await page.locator('.product-selection-prompt').filter({ hasText: 'Choose your color' }).waitFor();
        assert.equal(await page.locator('.product-configurator .design-cat-list').count(), 0);
        assert.equal(await page.locator('.design-bag-slot').count(), 3);
        await page.locator('.design-bag-slot img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
        await page.screenshot({ path: path.join(output, (mobile ? 'mobile' : 'desktop') + '-original-stage.png'), fullPage: true });
        await page.goto(base + '/shop?color=noir&product=demo-urban-chic-laptop-backpack');
        await page.locator('.product-selection-prompt').filter({ hasText: 'Your selection' }).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Noir', exact: true }).getAttribute('aria-pressed'), 'true');
        assert.equal(await page.getByRole('button', { name: 'Add to cart', exact: true }).isEnabled(), true);
        assert.deepEqual(errors, []);
        console.log(`${mobile ? 'Mobile' : 'Desktop'} live API: 12 products, 5 backpacks, real photos, Camel selection and quantity 2 cart passed.`);
      } finally {
        const session = await page.evaluate(() => localStorage.getItem('cart_session')).catch(() => null);
        if (session) {
          const cleared = await context.request.delete(`${api}/api/v1/store/cart/items`, { headers: { 'X-Cart-Session': session, Accept: 'application/json' } });
          assert.equal(cleared.ok(), true);
        }
        await context.close();
      }
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
