const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:5173';

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', err => errors.push(err.message));

  await context.addInitScript(() => localStorage.setItem('backoffice_token', 'vortix-interface-test'));
  await context.route('**/api/v1/admin/me', r => r.fulfill({ json: { data: {
    id: 1, name: 'Interface test', email: 'interface@example.test', is_active: true,
    roles: [{ id: 1, name: 'super_admin' }], permissions: ['*'], branch_id: null, branch: null,
  } } }));
  await context.route('**/api/v1/admin/products**', r => r.fulfill({ json: {
    data: [
      { id: 1, name_ar: 'شنطة جلد طبيعي', name_en: 'Leather Bag', code: 'BAG-001', sales_channel: 'both', status: 'published', variants_count: 3, min_retail_price: 1200, max_retail_price: 1500, is_featured: true, main_image: null, category: { id: 1, name_ar: 'حقائب نسائية' } },
    ],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
  } }));
  await context.route('**/api/v1/admin/categories**', r => r.fulfill({ json: { data: [] } }));

  try {
    // 1. Check Header & Sidebar navigation structure
    await page.goto(office + '/dashboard');
    await page.locator('#brand-home-btn').waitFor();

    const headerNav = page.locator('#app-viewport nav[aria-label="Primary Navigation"]');
    await headerNav.waitFor();

    // In header nav: every button must contain text words, and no button should be a side-nav-btn with only an svg
    const headerButtons = headerNav.locator('button');
    const headerBtnCount = await headerButtons.count();
    assert.ok(headerBtnCount >= 4, 'Header nav should have buttons for overview, products, dealers, production, categories, etc.');

    for (let i = 0; i < headerBtnCount; i++) {
      const btn = headerButtons.nth(i);
      const text = (await btn.innerText()).trim();
      assert.ok(text.length > 0, `Header nav button at index ${i} must have words/text, found: "${text}"`);
      const isSideBtn = await btn.evaluate(el => el.classList.contains('side-nav-btn'));
      assert.equal(isSideBtn, false, `Header nav button "${text}" must not have side-nav-btn class`);
    }

    // Verify products, dealers, production text buttons exist in header
    const productsNavBtn = headerNav.locator('[data-nav="products"]');
    assert.equal(await productsNavBtn.count(), 1, 'Header must have data-nav="products"');
    assert.equal(await productsNavBtn.innerText(), 'المنتجات', 'Header products button must be "المنتجات"');

    const dealersNavBtn = headerNav.locator('[data-nav="dealers"]');
    assert.equal(await dealersNavBtn.count(), 1, 'Header must have data-nav="dealers"');
    assert.equal(await dealersNavBtn.innerText(), 'التجار والموزعون');

    const productionNavBtn = headerNav.locator('[data-nav="production"]');
    assert.equal(await productionNavBtn.count(), 1, 'Header must have data-nav="production"');
    assert.equal(await productionNavBtn.innerText(), 'استلام الإنتاج');

    // Verify Sidebar has icons for products, dealers, production, categories
    const sidebar = page.locator('aside');
    const productsSideBtn = sidebar.locator('button[data-side="products"]');
    assert.equal(await productsSideBtn.count(), 1, 'Sidebar must have button[data-side="products"]');
    assert.ok(await productsSideBtn.locator('svg').count() >= 1, 'Sidebar products button must contain an icon svg');

    const dealersSideBtn = sidebar.locator('button[data-side="dealers"]');
    assert.equal(await dealersSideBtn.count(), 1, 'Sidebar must have button[data-side="dealers"]');
    assert.ok(await dealersSideBtn.locator('svg').count() >= 1, 'Sidebar dealers button must contain an icon svg');

    const productionSideBtn = sidebar.locator('button[data-side="production"]');
    assert.equal(await productionSideBtn.count(), 1, 'Sidebar must have button[data-side="production"]');
    assert.ok(await productionSideBtn.locator('svg').count() >= 1, 'Sidebar production button must contain an icon svg');

    // 2. Navigate to Products page
    await productsNavBtn.click();
    await page.waitForURL(url => url.hash.includes('/products'));
    await page.getByRole('heading', { name: 'المنتجات', exact: true }).waitFor();

    // Check light mode styles on products page initially
    const getSurfaceTheme = () => page.locator('[data-catalog-mock] .catalog-surface').evaluate(el => ({
      className: el.className,
      background: getComputedStyle(el).backgroundColor,
      color: getComputedStyle(el).color,
    }));

    const lightStyles = await getSurfaceTheme();
    console.log('Products page in light mode:', lightStyles);
    assert.ok(lightStyles.className.includes('theme-light'), 'Products surface must have theme-light in light mode');

    // 3. Switch to Dark Mode while on Products page
    const darkBtn = page.locator('#theme-dark-btn');
    await darkBtn.click();
    await page.waitForTimeout(800); // allow wipe animation to finish

    const darkStyles = await getSurfaceTheme();
    console.log('Products page in dark mode:', darkStyles);
    assert.ok(darkStyles.className.includes('theme-dark'), 'Products surface must have theme-dark in dark mode');
    assert.equal(darkStyles.background, 'rgb(16, 13, 19)', 'Products surface background in dark mode must match #100d13');
    assert.equal(darkStyles.color, 'rgb(245, 243, 247)', 'Products surface text color in dark mode must match #f5f3f7');

    // Verify product table cards also have dark background
    const cardBg = await page.locator('[data-catalog-mock] .v-card').first().evaluate(el => getComputedStyle(el).backgroundColor);
    console.log('Card background in dark mode:', cardBg);
    assert.equal(cardBg, 'rgb(23, 20, 26)', 'Product cards in dark mode must match #17141a');

    // 4. Switch back to Light Mode while on Products page
    const lightBtn = page.locator('#theme-light-btn');
    await lightBtn.click();
    await page.waitForTimeout(800);

    const revertedStyles = await getSurfaceTheme();
    console.log('Products page after reverting to light mode:', revertedStyles);
    assert.ok(revertedStyles.className.includes('theme-light'), 'Products surface must return to theme-light');
    assert.equal(revertedStyles.background, 'rgb(242, 239, 243)', 'Products surface background in light mode must match #f2eff3');

    // 5. Navigate to Overview in Dark Mode, then back to Products: verify Dark Mode is preserved
    await darkBtn.click();
    await page.waitForTimeout(500);
    await headerNav.locator('[data-nav="overview"]').click();
    await page.waitForURL(url => url.hash === '#/dashboard' || !url.hash);
    await page.locator('#overview-balance-counter').waitFor();

    // Click products in header again
    await headerNav.locator('[data-nav="products"]').click();
    await page.waitForURL(url => url.hash.includes('/products'));
    await page.getByRole('heading', { name: 'المنتجات', exact: true }).waitFor();

    const preservedDarkStyles = await getSurfaceTheme();
    console.log('Products page navigated from dark overview:', preservedDarkStyles);
    assert.ok(preservedDarkStyles.className.includes('theme-dark'), 'Products must immediately render dark mode when navigated from dark overview');
    assert.equal(preservedDarkStyles.background, 'rgb(16, 13, 19)');

    assert.deepEqual(errors, [], 'No browser runtime errors');
    console.log('ALL TESTS PASSED: Header words, Sidebar icons, and Products Dark Mode color parity!');
  } finally {
    await browser.close();
  }
})().catch(err => {
  console.error('Test failed:', err);
  process.exitCode = 1;
});
