const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const storeUrl = process.env.E2E_STOREFRONT_URL || 'http://localhost:3000';
const apiUrl = process.env.E2E_API_URL || 'http://127.0.0.1:8000';

(async () => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'chrome', headless: true });
  let guestSession;
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 950 } });
    const page = await context.newPage();
    const errors = [], additions = [], deletions = [];
    page.on('pageerror', error => errors.push(error.message));
    context.on('request', request => {
      if (request.method() === 'POST' && request.url().endsWith('/cart/items')) additions.push(request.postDataJSON());
      if (request.method() === 'DELETE' && request.url().includes('/cart/items')) deletions.push(request.url());
    });
    const closePopup = async target => {
      await target.evaluate(() => {
        for (let id = 1; id < 100; id++) sessionStorage.setItem(`sophie-popup-${id}`, '1');
      });
    };
    const response = await page.goto(storeUrl);
    const csp = response.headers()['content-security-policy'];
    assert.match(csp, /script-src .*'nonce-[^']+'/);
    assert.ok(!csp.split(';').find(part => part.trim().startsWith('script-src')).includes('unsafe-inline'));
    await page.evaluate(() => { document.addEventListener('securitypolicyviolation', e => { window.cspViolations ??= []; window.cspViolations.push(e.violatedDirective); }); });
    await page.evaluate(() => { document.body.insertAdjacentHTML('beforeend', `<img src="/missing-csp-test-image" onerror="window.injectedScriptExecuted = true">`); });
    await page.waitForFunction(() => window.cspViolations?.includes('script-src-attr'));
    assert.equal(await page.evaluate(() => window.injectedScriptExecuted), undefined);
    await closePopup(page);
    const button = page.locator('#best-sellers .product-card').getByRole('button', { name: '+ Add to Cart', exact: true }).first();
    await button.waitFor();
    await button.click({ force: true });
    await page.getByRole('button', { name: '✓ Added', exact: true }).first().waitFor();
    guestSession = await page.evaluate(() => localStorage.getItem('cart_session'));
    assert.ok(guestSession);
    assert.deepEqual(Object.keys(additions[0]).sort(), ['product_variant_id', 'qty']);
    await page.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
    const cart = page.getByRole('dialog');
    await cart.getByRole('heading', { name: 'Shopping Cart (1)', exact: true }).waitFor();
    await page.keyboard.press('Shift+Tab');
    assert.equal(await cart.evaluate(el => el.contains(document.activeElement)), true);
    await page.keyboard.press('Escape');
    await cart.waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
    await cart.getByRole('button', { name: /Increase quantity/ }).click();
    await cart.getByRole('heading', { name: 'Shopping Cart (2)', exact: true }).waitFor();
    await page.reload();
    await closePopup(page);
    await page.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
    await page.getByRole('heading', { name: 'Shopping Cart (2)', exact: true }).waitFor();
    const second = await context.newPage();
    await second.goto(storeUrl); await closePopup(second);
    await second.getByRole('button', { name: 'Shopping Cart', exact: true }).click();
    await second.getByRole('heading', { name: 'Shopping Cart (2)', exact: true }).waitFor();
    await second.getByRole('button', { name: /Decrease quantity/ }).click();
    await second.getByRole('heading', { name: 'Shopping Cart (1)', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Shopping Cart (1)', exact: true }).waitFor();
    await second.getByRole('button', { name: 'Clear Cart', exact: true }).click();
    await second.getByText('Your cart is empty', { exact: true }).waitFor();
    assert.equal(deletions.length, 1, 'Clearing the whole cart uses one request');
    assert.ok(deletions[0].endsWith('/cart/items'));
    assert.deepEqual(errors, []);
    console.log('PASS: real server cart, variant-only requests, quantity changes, reload persistence, cross-tab sync and clearing.');
  } finally {
    if (guestSession) {
      const headers = { 'X-Cart-Session': guestSession, Accept: 'application/json' };
      const response = await fetch(`${apiUrl}/api/v1/store/cart/items`, { headers });
      if (response.ok) {
        const cart = (await response.json()).data;
        for (const item of cart.items) await fetch(`${apiUrl}/api/v1/store/cart/items/${item.id}`, { method: 'DELETE', headers });
      }
    }
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
