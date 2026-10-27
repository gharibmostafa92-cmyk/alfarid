const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.STOREFRONT_URL || 'http://localhost:3000';
const api = process.env.STOREFRONT_API_URL || 'http://127.0.0.1:8000';
const output = path.resolve(__dirname, '../../../test-results/storefront-images');

(async () => {
  // Read actual public product/media only. Browser cart and banner fixtures never write business data.
  const listing = await (await fetch(`${api}/api/v1/store/products?per_page=1`)).json();
  const card = listing.data.find(item => item.image);
  assert.ok(card, 'A product with real photography is required');
  const detail = (await (await fetch(`${api}/api/v1/store/products/${card.slug}`)).json()).data;
  const category = { ...card.category, image: card.image, products_count: 1, children: [] };
  const imagePath = new URL(card.image).pathname.replace(/^\/storage\//, '/store-media/');
  const proxy = await fetch(`${base}${imagePath}`);
  assert.equal(proxy.status, 200, 'Same-origin public media must be served by the production store');
  assert.match(proxy.headers.get('content-type'), /^image\//);
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const mobile of [false, true]) {
      const context = await browser.newContext({ viewport: mobile ? { width: 375, height: 812 } : { width: 1440, height: 1000 } });
      let empty = false, broken = false;
      await context.route('**/api/v1/store/**', async route => {
        const request = route.request(), url = new URL(request.url());
        const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
        const ok = data => route.fulfill({ headers, json: { data } });
        if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
        assert.equal(request.method(), 'GET', `Image verification must be read-only: ${url.pathname}`);
        if (url.pathname.endsWith('/branding')) return ok({ name: 'Image Test', logo: card.image });
        if (url.pathname.endsWith('/categories')) return ok([category]);
        if (url.pathname.endsWith('/cities')) return ok([{ id: 1, name_ar: 'القاهرة', name_en: 'Cairo' }]);
        if (url.pathname.endsWith('/cart/items')) return ok({ customer_id: null, cart_token: 'image-test-session', items: empty ? [] : [{
          id: 1, product_id: card.id, product_variant_id: card.default_variant_id,
          product_name: card.name_en || card.name_ar, product_slug: card.slug, qty: 1, unit_price: card.price,
          line_total: card.price, available: 5, max_qty: 5, priced: true,
          image: broken ? 'http://localhost:8000/storage/missing-image.jpg' : card.image,
        }], totals: { subtotal: card.price, pricing_discount: 0, items_total: card.price, coupon_discount: 0, total: card.price, coupon_code: null } });
        if (url.pathname.endsWith('/home')) return ok({ best_sellers: [card], latest_products: [card], discounted_products: [],
          banners: ['main_slider', 'middle_banner', 'popup'].map((position, index) => ({ id: index + 1, position,
            image: card.image, title: `Image ${position}`, headline: 'Real photography', href: '/shop', is_external: false })) });
        if (url.pathname.endsWith('/showcase')) return ok([detail]);
        if (url.pathname.endsWith(`/products/${card.slug}`)) return ok(detail);
        if (url.pathname.endsWith('/products')) return route.fulfill({ headers, json: { data: [card], meta: { current_page: 1, last_page: 1, total: 1, per_page: 24 } } });
        if (url.pathname.endsWith('/catalog/filters')) return ok({ total: 1, colors: [], price_ranges: [], occasions: [] });
        return ok([]);
      });
      const page = await context.newPage(), errors = [], blockedImages = [];
      page.setDefaultTimeout(20000);
      page.on('pageerror', error => errors.push(error.message));
      page.on('console', message => { const text = message.text().toLowerCase(); if (text.includes('image') && text.includes('security policy')) blockedImages.push(text); });
      const loaded = async locator => {
        await locator.waitFor({ state: 'visible' });
        await page.waitForFunction(img => img.complete && img.naturalWidth > 0, await locator.elementHandle());
        assert.match(await locator.getAttribute('src'), /^\/store-media\//);
      };
      await page.goto(`${base}/`, { waitUntil: 'domcontentloaded' });
      await loaded(page.locator('.hero-banner-img'));
      await loaded(page.locator('#best-sellers img').first());
      await loaded(page.getByAltText('Image middle_banner'));
      await page.getByRole('dialog', { name: 'Image popup' }).waitFor();
      await loaded(page.getByRole('dialog').locator('img'));
      await page.getByRole('button', { name: 'Close offer' }).click();
      await loaded(page.locator('[data-store-header] a[title] img').first());
      await page.goto(`${base}/shop?category=${encodeURIComponent(category.slug)}`);
      await loaded(page.locator('.product-card .product-img-box img').first());
      if (!mobile) {
        await page.locator('nav a[aria-controls="mega-dropdown"]').first().focus();
        await page.keyboard.press('ArrowDown');
        await loaded(page.locator('#spotlight-img'));
        await page.screenshot({ path: path.join(output, 'desktop-menu.png') });
        await page.keyboard.press('Escape');
      }
      await page.goto(`${base}/shop?product=${encodeURIComponent(card.slug)}`);
      await loaded(page.locator('.product-stage-image'));
      await page.goto(`${base}/design`);
      await loaded(page.locator('.product-stage-image'));
      await page.goto(`${base}/checkout`);
      await loaded(page.locator('.bag-item-thumb img'));
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-checkout.png`) });
      await page.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
      await loaded(page.getByRole('dialog', { name: 'Shopping cart' }).locator('img'));
      await page.screenshot({ path: path.join(output, `${mobile ? 'mobile' : 'desktop'}-cart.png`) });
      await page.getByRole('button', { name: 'Close cart' }).click();
      empty = true; await page.reload({ waitUntil: 'domcontentloaded' });
      await loaded(page.locator('.checkout-suggestion-card img').first());
      empty = false; broken = true;
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.locator('.bag-item-thumb svg').waitFor();
      assert.equal(await page.locator('.bag-item-thumb img').count(), 0);
      assert.deepEqual(errors, []);
      assert.deepEqual(blockedImages, []);
      console.log(`${mobile ? '375px mobile' : 'desktop'}: real images loaded in banners, popup, branding, catalog, product, showcase, checkout, cart and suggestions; failed image fallback passed`);
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
