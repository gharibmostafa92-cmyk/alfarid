const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const officeUrl = process.env.E2E_BACKOFFICE_URL || 'http://localhost:5173';
const storeUrl = process.env.E2E_STOREFRONT_URL || 'http://localhost:3000';
const posUrl = process.env.E2E_POS_URL || 'http://localhost:3002';
const apiUrl = process.env.E2E_API_URL || 'http://127.0.0.1:8000';
const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' };

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'chrome' });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    context.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    await context.addInitScript(() => {
      try { localStorage.setItem('backoffice_token', 'branding-test-token'); } catch { /* Sandboxed frames have no storage. */ }
    });
    const state = await require('./admin-api-routes.cjs')(context);
    let unavailable = false;
    const brandingRequests = [];
    await context.route('**/api/v1/store/branding', route => {
      brandingRequests.push(route.request().headers());
      if (unavailable) return route.fulfill({ status: 503, headers, json: { message: 'Unavailable' } });
      const company = state.settings.data.company;
      return route.fulfill({ headers, json: { data: {
        name: company.company_name || 'Alfarid', logo: company.company_logo,
        email: company.company_email, phone: company.company_phone, address: company.company_address,
      } } });
    });
    await context.route('**/api/v1/store/home', route => route.fulfill({ headers, json: { data: {
      banners: [], best_sellers: [], latest_products: [], discounted_products: [],
    } } }));
    await context.route('https://branding.example/logo.svg', route => route.fulfill({
      contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="5" fill="#111"/><path d="M10 28 20 10l10 18" fill="none" stroke="white" stroke-width="4"/></svg>',
    }));
    await context.route('https://branding.example/missing.svg', route => route.fulfill({ status: 404, body: '' }));

    const store = await context.newPage();
    await store.clock.install();
    await store.goto(storeUrl);
    await store.locator('header a[title="Alfarid"]').waitFor();
    await store.evaluate(() => {
      window.__brandingDocument = 'unchanged';
      document.title = 'Product page title';
      const icon = document.createElement('link'); icon.rel = 'icon'; icon.dataset.nextOwned = 'true'; icon.href = '/icon.svg'; document.head.appendChild(icon);
    });
    assert.equal(await store.locator('a[href^="mailto:contact@sophiebags"]').count(), 0);

    const office = await context.newPage();
    await office.goto(`${officeUrl}/dashboard#/settings`);
    await office.locator('#brand-home-btn').getByText('Alfarid', { exact: true }).waitFor();
    const settings = office.locator('[data-admin-mock="settings"]');
    const name = 'الفريد للحقائب';
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').fill(name);
    await settings.getByLabel('البريد الإلكتروني للشركة', { exact: true }).fill('hello@example.com');
    await settings.getByLabel('رقم الهاتف الرسمي', { exact: true }).fill('+201000000000');
    await settings.getByLabel('عنوان المقر الرئيسي', { exact: true }).fill('Cairo');
    await settings.getByPlaceholder('https://cdn.company.com/logo.png').fill('https://branding.example/logo.svg');
    const saved = office.waitForResponse(response => response.request().method() === 'PUT' && response.url().endsWith('/settings'));
    await settings.getByRole('button', { name: /حفظ/ }).click();
    assert.equal((await saved).status(), 200);
    await office.locator('#brand-home-btn').getByText(name, { exact: true }).waitFor();
    await office.waitForFunction(expected => document.title === `${expected} | لوحة الإدارة`, name);
    assert.equal(state.lastSettingsPatch.company_name, name);

    await store.clock.fastForward(30_001);
    const beforeFocus = brandingRequests.length;
    await store.evaluate(() => { window.dispatchEvent(new Event('focus')); document.dispatchEvent(new Event('visibilitychange')); });
    await store.locator(`header a[title="${name}"]`).waitFor();
    assert.equal(brandingRequests.length, beforeFocus + 1, 'Focus and visibility must share one branding request');
    assert.equal(await store.title(), 'Product page title');
    assert.equal(await store.locator('link[data-next-owned]').count(), 1);
    const beforeIdle = brandingRequests.length;
    await store.clock.fastForward(120_000);
    assert.equal(brandingRequests.length, beforeIdle, 'Storefront branding must not poll in the background');
    assert.equal(await store.evaluate(() => window.__brandingDocument), 'unchanged');
    assert.equal(await store.locator('a[href="mailto:hello@example.com"]').count(), 1);
    assert.equal(await store.locator('a[href="tel:+201000000000"]').count(), 1);
    await store.locator('header img[src="https://branding.example/logo.svg"]').waitFor();
    assert.equal(await store.locator('.site-footer-wrap').getByText(name, { exact: true }).count(), 2);

    // An authenticated staff tab intentionally redirects the shared sign-in page to the backoffice.
    const anonymous = await browser.newContext();
    await anonymous.route('**/api/v1/store/branding', route => route.fulfill({ headers, json: { data: {
      name, logo: null, email: null, phone: null, address: null,
    } } }));
    const login = await anonymous.newPage();
    login.on('pageerror', error => errors.push(error.message));
    await login.goto(`${storeUrl}/auth/login`);
    await login.locator('.luxury-brand-tag').getByText(name, { exact: true }).waitFor();
    await login.getByText(`سجّل الدخول إلى حسابك في ${name}`, { exact: true }).waitFor();
    await login.goto(`${storeUrl}/auth/register`);
    await login.getByText(`أنشئ حسابك في ${name} وتابع طلباتك بسهولة.`, { exact: true }).waitFor();

    const pos = await context.newPage();
    await pos.goto(posUrl);
    await pos.getByText(`${name} POS`, { exact: true }).waitFor();
    await pos.waitForFunction(expected => document.title === `${expected} | نقاط البيع`, name);

    unavailable = true;
    await store.clock.fastForward(30_001);
    const failedBranding = store.waitForResponse(response => response.url().endsWith('/store/branding') && response.status() === 503);
    await store.evaluate(() => window.dispatchEvent(new Event('focus')));
    await failedBranding;
    assert.equal(await store.locator(`header a[title="${name}"]`).count(), 1);
    unavailable = false;

    state.settings.data.company.company_logo = 'https://branding.example/missing.svg';
    await store.clock.fastForward(30_001);
    await store.evaluate(() => window.dispatchEvent(new Event('focus')));
    await store.waitForFunction(() => !document.querySelector('header img'));
    assert.equal(await store.locator(`header a[title="${name}"] svg`).count(), 1);
    state.settings.data.company.company_logo = 'https://branding.example/logo.svg';
    await store.clock.fastForward(30_001);
    await store.evaluate(() => window.dispatchEvent(new Event('focus')));
    await store.locator('header img').waitFor();

    for (const request of brandingRequests) assert.equal(request.authorization, undefined, 'Public branding must not send stored authentication');
    assert.deepEqual(errors, []);

    const actualBranding = (await (await fetch(`${apiUrl}/api/v1/store/branding`)).json()).data;
    const html = await (await fetch(storeUrl)).text();
    const title = await store.evaluate(content => new DOMParser().parseFromString(content, 'text/html').title, html);
    assert.equal(title, `${actualBranding.name} | Bag Collection`, 'Server HTML title must use the saved identity');
    assert.ok(html.includes('property="og:site_name"'));

    if (process.env.BRANDING_SCREENSHOTS_DIR) {
      const output = process.env.BRANDING_SCREENSHOTS_DIR;
      fs.mkdirSync(output, { recursive: true });
      await store.screenshot({ path: path.join(output, 'branding-desktop.png'), fullPage: true });
      await office.screenshot({ path: path.join(output, 'branding-admin.png'), fullPage: true });
      await store.setViewportSize({ width: 375, height: 900 });
      assert.ok(await store.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await store.screenshot({ path: path.join(output, 'branding-mobile.png'), fullPage: true });
    }
    console.log('PASS: admin rename, shared identity/logo/contacts, titles, login/register/POS, live focus refresh, failed API/logo fallback, and server metadata.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
