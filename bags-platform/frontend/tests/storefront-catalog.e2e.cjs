const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.STOREFRONT_URL || 'http://localhost:3000';
const output = path.resolve(__dirname, '../../../test-results/storefront-catalog');
const image = name => ({ id: 1, url: `https://catalog.test/${name}.png`, thumb: `https://catalog.test/${name}-thumb.png` });
const category = { id: 1, name_en: 'Backpacks', name_ar: 'حقائب الظهر', slug: 'demo-backpacks',
  description_en: 'Bags for everyday travel.', description_ar: null, products_count: 2, image: null, children: [] };
const variant = (id, color, size, stock = true) => ({ id, sku: `SP-VG-${color}-${size}`, color_name_en: color,
  color_name_ar: null, color_hex: color === 'Rose' ? '#e89aa8' : '#d4b38d', size, capacity: size === 'Large' ? '20L' : '14L',
  images: [image(`${color}-${size}`), image(`${color}-${size}-back`)], price: 1250, old_price: null, in_stock: stock });
const product = { id: 1, slug: 'demo-vogue', name_en: 'Vogue Elegance Backpack', name_ar: 'حقيبة',
  category, image: image('bag').url, price: 1250, old_price: null, discount_percentage: null,
  is_featured: true, sales_count: 10, in_stock: true, default_variant_id: 10, is_new: true,
  badge_en: null, badge_ar: null, colors: [], description_en: 'A real product description.', description_ar: null,
  material_en: 'Leather', material_ar: null, warranty_months: 12, images: [image('bag')], occasions: ['work'],
  variants: [variant(10, 'Rose', 'Medium'), variant(11, 'Rose', 'Large'), variant(12, 'Camel', 'Medium')] };
const soldOut = { ...product, id: 2, slug: 'demo-sold-out', name_en: 'Soft Rose Daypack', in_stock: false,
  variants: [variant(20, 'Rose', 'Medium', false)] };
const third = { ...product, id: 3, slug: 'demo-other', name_en: 'Other Bag' };

(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const mobile of [false, true]) {
      const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 } });
      const errors = [], writes = [];
      let items = [], failProducts = false, showcase = [product, soldOut];
      await context.route('https://catalog.test/**', route => route.fulfill({ contentType: 'image/png',
        body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZcAAAAASUVORK5CYII=', 'base64') }));
      await context.route('**/api/v1/store/**', async route => {
        const request = route.request(), url = new URL(request.url());
        const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': 'GET, POST, OPTIONS' };
        const ok = data => route.fulfill({ headers, json: { data } });
        if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
        if (url.pathname.endsWith('/branding')) return ok({ name: 'Sophie', logo: null });
        if (url.pathname.endsWith('/cart/items')) {
          if (request.method() === 'POST') {
            const body = request.postDataJSON(); writes.push(body);
            items = [{ id: 1, product_id: 1, product_variant_id: body.product_variant_id, product_name: product.name_en,
              sku: 'SKU', qty: (items[0]?.qty ?? 0) + body.qty, unit_price: 1250, available: 10, priced: true, line_total: 2500 }];
          }
          return ok({ id: 1, cart_token: 'demo-session', customer_id: null, items, totals: { total: 2500 } });
        }
        if (url.pathname.endsWith('/categories')) return ok([category]);
        if (url.pathname.endsWith('/catalog/filters')) return ok({ total: 2,
          colors: [{ id: 'rose', name_en: 'Rose', name_ar: null, hex: '#e89aa8', count: 2 }, { id: 'camel', name_en: 'Camel', name_ar: null, hex: '#d4b38d', count: 1 }],
          price_ranges: [{ id: 'under-800', min: null, max: 799.99, count: 0 }, { id: '800-1500', min: 800, max: 1500, count: 2 }],
          occasions: [{ id: 'work', count: 1 }] });
        if (url.pathname.endsWith('/showcase')) return ok(showcase);
        if (url.pathname.endsWith('/similar')) return ok([soldOut]);
        if (url.pathname.endsWith('/products/demo-vogue')) return ok(product);
        if (url.pathname.endsWith('/products/demo-sold-out')) return ok(soldOut);
        if (url.pathname.includes('/products/')) return route.fulfill({ status: 404, headers, json: { message: 'Not found' } });
        if (url.pathname.endsWith('/products')) {
          if (failProducts) return route.fulfill({ status: 422, headers, json: { message: 'Catalog unavailable' } });
          const data = url.searchParams.get('q') === 'missing' || url.searchParams.get('search') === 'missing' ? [] : [product, soldOut, third];
          return route.fulfill({ headers, json: { data, meta: { total: data.length, current_page: 1, last_page: 1, per_page: 24 } } });
        }
        return ok([]);
      });
      const page = await context.newPage();
      page.setDefaultTimeout(20000);
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(`${base}/shop?sort=newest`, { waitUntil: 'domcontentloaded' });
      await page.locator('.product-card:not(.skeleton)').first().waitFor();
      assert.equal(await page.locator('.sort-select').inputValue(), 'newest');
      await page.getByRole('button', { name: 'Backpacks', exact: true }).click();
      await page.waitForURL('**/shop?category=demo-backpacks&sort=newest');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.locator('.cat-hero-title .bold-text').filter({ hasText: 'BACKPACKS' }).waitFor();
      await page.locator('.swatch-btn[title="Rose"]').click();
      await page.waitForURL('**color=rose**');
      assert.equal(await page.locator('.color-swatches-grid .swatch-btn').count(), 3);
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-shop.png`), fullPage: true });
      await page.locator('.product-card').first().click();
      await page.locator('.product-heading h1').waitFor();
      assert.equal(await page.locator('.pdp-reviews-bar').count(), 0);
      assert.equal(await page.locator('.product-selection-prompt').textContent(), 'Choose your size');
      assert.equal(await page.locator('.design-bag-slot').count(), 3);
      await page.getByRole('button', { name: 'Medium (14L)', exact: true }).click();
      await page.getByRole('button', { name: 'Next product image', exact: true }).click();
      assert.match(await page.locator('.product-stage-image').getAttribute('src'), /Rose-Medium-back\.png$/);
      await page.getByRole('button', { name: 'Large (20L)', exact: true }).click();
      assert.equal(await page.getByRole('button', { name: 'Camel', exact: true }).isDisabled(), true);
      await page.getByRole('button', { name: 'Medium (14L)', exact: true }).click();
      await page.locator('.product-color-options button[aria-label="Camel"]').click();
      assert.equal(await page.getByRole('button', { name: 'Large (20L)', exact: true }).isEnabled(), true);
      assert.match(await page.locator('.product-variant-sku').innerText(), /Camel-Medium/i);
      await page.getByRole('button', { name: 'Increase quantity' }).click();
      await page.locator('.product-add-button').click();
      await page.locator('[data-cart-count]').filter({ hasText: /^2$/ }).waitFor();
      assert.equal(writes[0].qty, 2);
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-detail.png`), fullPage: true });
      await page.goBack();
      await page.locator('.catalog-view').waitFor();
      assert.equal(new URL(page.url()).searchParams.get('color'), 'rose');
      await page.goto(`${base}/shop?product=demo-sold-out`);
      await page.getByRole('button', { name: /Sold out/i }).waitFor();
      assert.equal(await page.locator('.product-add-button').isDisabled(), true);
      await page.goto(`${base}/shop?product=missing`);
      await page.getByText('THIS PIECE IS NO LONGER AVAILABLE', { exact: true }).waitFor();
      await page.goto(`${base}/shop?q=missing`);
      await page.getByText('NO BAGS FOUND', { exact: true }).waitFor();
      failProducts = true;
      await page.goto(`${base}/shop?category=error`);
      await page.getByText("COULDN'T LOAD PRODUCTS", { exact: true }).waitFor();
      failProducts = false;
      await page.getByRole('button', { name: 'Retry', exact: true }).click();
      await page.locator('.product-card:not(.skeleton)').first().waitFor();
      await page.goto(`${base}/design`);
      await page.getByRole('button', { name: 'EXPLORE DETAILS' }).waitFor();
      await page.getByRole('button', { name: 'EXPLORE DETAILS' }).click();
      await page.getByRole('button', { name: 'Medium (14L)', exact: true }).click();
      await page.locator('.product-color-options button[aria-label="Camel"]').click();
      assert.equal(await page.getByRole('button', { name: 'Medium (14L)', exact: true }).getAttribute('aria-pressed'), 'true');
      await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
      await page.locator('[data-cart-count]').filter({ hasText: /^3$/ }).waitFor();
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-showcase.png`), fullPage: true });
      await page.goto(`${base}/design`);
      await page.getByRole('button', { name: 'EXPLORE DETAILS' }).waitFor();
      await page.getByRole('button', { name: 'Grid', exact: true }).click();
      await page.locator('.design-grid-card').filter({ hasText: 'Other Bag' }).click();
      await page.waitForURL('**/shop?product=demo-other');
      showcase = [product];
      await page.goto(`${base}/design`);
      await page.getByRole('button', { name: 'EXPLORE DETAILS' }).waitFor();
      assert.equal(await page.locator('.design-carousel-arrow').count(), 0);
      await page.keyboard.press('ArrowRight');
      await page.getByText(product.name_en, { exact: false }).first().waitFor();
      showcase = [];
      await page.goto(`${base}/design`);
      await page.getByText('Showcase coming soon.', { exact: true }).waitFor();
      assert.deepEqual(errors, []);
      await context.close();
      console.log(`${mobile ? 'Mobile' : 'Desktop'} catalog, details, cart, recovery and showcase passed.`);
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
