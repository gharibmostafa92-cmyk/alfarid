const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { installFixtures } = require('./fixtures/storefront-responsive.cjs');

// Run against the development server: node --test tests/storefront-responsive.e2e.cjs
// Set PLAYWRIGHT_CHANNEL=msedge to use an installed Edge browser.
const baseURL = process.env.STOREFRONT_URL || 'http://localhost:3001';
const launchOptions = { headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) };

test('mobile navigation stays at the viewport bottom and cart covers the viewport', async () => {
  const browser = await chromium.launch(launchOptions);
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 740 } });
    await page.goto(baseURL, { waitUntil: 'networkidle' });
    const nav = page.getByRole('navigation', { name: 'Mobile quick navigation' });
    const bounds = await nav.boundingBox();
    assert.ok(Math.abs(bounds.y + bounds.height - 740) < 2, 'bottom navigation must be anchored to the viewport');
    const header = await page.locator('[data-store-header]').boundingBox();
    assert.ok(header.y + header.height < bounds.y, 'navigation must not cover the header');
    await nav.getByRole('button', { name: 'Cart', exact: true }).click();
    const cart = page.getByRole('dialog', { name: 'Shopping cart' });
    const cartBounds = await cart.boundingBox();
    assert.ok(cartBounds.height >= 738, 'cart must fill the viewport height');
    await page.keyboard.press('Escape');
    await cart.waitFor({ state: 'hidden' });
    await page.close();
  } finally { await browser.close(); }
});

test('retail routes fit phone, tablet, laptop and desktop widths with populated content', async () => {
  const browser = await chromium.launch(launchOptions);
  const routes = ['/', '/shop', '/categories', '/shop?product=responsive-test-bag', '/design', '/showcase',
    '/checkout', '/checkout/success', '/orders/UI-ORDER/track', '/account', '/account/orders', '/account/wallet', '/account/returns',
    '/auth/login', '/auth/register', '/auth/forgot-password', '/auth/reset-password'];
  try {
    for (const width of [320, 768, 1024, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await installFixtures(page);
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      for (const route of routes) {
        errors.length = 0;
        await page.goto(`${baseURL}${route}`, { waitUntil: 'networkidle' });
        const dimensions = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
        assert.ok(dimensions.content <= dimensions.viewport + 1, `${route} overflows at ${width}px`);
        assert.deepEqual(errors, [], `${route} has runtime errors at ${width}px`);
        if (process.env.RESPONSIVE_SCREENSHOTS) {
          const fs = require('node:fs');
          const path = require('node:path');
          fs.mkdirSync(process.env.RESPONSIVE_SCREENSHOTS, { recursive: true });
          await page.screenshot({ path: path.join(process.env.RESPONSIVE_SCREENSHOTS, `${width}-${route.replace(/[^a-z0-9]/gi, '_')}.png`), fullPage: true });
        }
      }
      await page.close();
    }
  } finally { await browser.close(); }
});

test('mobile menu traps focus, restores its trigger and unlocks scrolling on desktop', async () => {
  const browser = await chromium.launch(launchOptions);
  try {
    const page = await browser.newPage({ viewport: { width: 390, height: 740 } });
    await installFixtures(page);
    await page.goto(baseURL, { waitUntil: 'networkidle' });
    const trigger = page.getByRole('button', { name: 'Toggle navigation menu' });
    await trigger.click();
    const menu = page.getByRole('dialog', { name: 'Mobile navigation menu' });
    await page.keyboard.press('Shift+Tab');
    assert.equal(await menu.locator(':focus').count(), 1);
    await page.keyboard.press('Escape');
    await menu.waitFor({ state: 'hidden' });
    assert.equal(await trigger.evaluate(element => element === document.activeElement), true);
    await trigger.click();
    await page.setViewportSize({ width: 1440, height: 900 });
    await menu.waitFor({ state: 'hidden' });
    assert.notEqual(await page.locator('body').evaluate(element => element.style.overflow), 'hidden');
    await page.close();
  } finally { await browser.close(); }
});

test('checkout cart stays above the fixed payment bar and search fits a narrow phone', async () => {
  const browser = await chromium.launch(launchOptions);
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 740 } });
    await installFixtures(page);
    await page.goto(`${baseURL}/checkout`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
    await page.getByRole('dialog', { name: 'Shopping cart' }).waitFor();
    assert.equal(await page.evaluate(() => !!document.elementFromPoint(160, 720)?.closest('[role="dialog"]')), true,
      'the payment bar must not cover cart actions');
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Search bags' }).fill('A very long search query to check narrow layouts');
    for (const element of await page.getByRole('search').locator('input, button').all()) {
      const bounds = await element.boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 321, 'search controls must stay inside the screen');
    }
    await page.close();
  } finally { await browser.close(); }
});

test('account errors stop the loading state and offer a working retry', async () => {
  const browser = await chromium.launch(launchOptions);
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 740 } });
    await installFixtures(page);
    await page.route('**/api/v1/store/account/orders', route => route.fulfill({ status: 500, json: { message: 'Test failure' } }), { times: 1 });
    await page.goto(`${baseURL}/account/orders`, { waitUntil: 'networkidle' });
    await page.locator('.checkout-banner[role="alert"]').waitFor();
    assert.equal(await page.getByRole('status', { name: 'Loading orders' }).count(), 0);
    await page.getByRole('button', { name: 'إعادة المحاولة', exact: true }).click();
    await page.getByRole('heading', { name: 'UI-ORDER-1234567890' }).waitFor();
    await page.close();
  } finally { await browser.close(); }
});

test('catalog hydrates without a viewport mismatch and closed filters cannot receive focus', async () => {
  const browser = await chromium.launch(launchOptions);
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 740 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${baseURL}/shop`, { waitUntil: 'networkidle' });
    assert.ok(!errors.some(message => message.includes('Hydration failed')), 'server and client must render the same initial filter state');
    const toggle = page.getByRole('button', { name: /Show Filters/ });
    await toggle.waitFor();
    assert.equal(await page.locator('#catalog-filters').isVisible(), false);
    await toggle.click();
    await page.locator('#catalog-filters').waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Hide Filters', exact: true }).last().click();
    assert.equal(await page.locator('#catalog-filters').isVisible(), false);
    await page.close();
  } finally { await browser.close(); }
});
