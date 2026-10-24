const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:5173';
const web = process.env.E2E_WEB_URL || 'http://localhost:3000';
const output = path.resolve('test-results/mobile-ui');
fs.mkdirSync(output, { recursive: true });

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const width of [320, 390, 768, 1440]) {
      const context = await browser.newContext({ viewport: { width, height: 844 }, hasTouch: width < 768, reducedMotion: 'reduce' });
      await context.addInitScript(() => localStorage.setItem('backoffice_token', 'mobile-ui-review'));
      await context.route('**/api/v1/admin/**', route => route.fulfill({ json: route.request().url().endsWith('/me') ? { data: {
        id: 1, name: 'Mobile review', roles: ['super_admin'], permissions: ['*'], is_active: true,
      } } : { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(office + '/dashboard');
      await page.locator('#brand-home-btn').waitFor();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No document overflow at ${width}`);
      if (width < 768) {
        const nav = page.getByRole('navigation', { name: 'التنقل الرئيسي على الموبايل', exact: true });
        assert.equal(await nav.getByRole('button').count(), 5);
        assert.ok(await nav.evaluate(element => element.scrollWidth <= element.clientWidth));
        for (const button of await nav.getByRole('button').all()) {
          const bounds = await button.boundingBox();
          assert.ok(bounds.width >= 44 && bounds.height >= 44);
        }
        await nav.locator('[data-mobile-nav="products"]').tap();
        await page.waitForURL(url => url.hash === '#/products');
        assert.equal(await nav.locator('[data-mobile-nav="products"]').getAttribute('aria-current'), 'page');
        await nav.getByRole('button', { name: 'المزيد', exact: true }).tap();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor();
        assert.equal(await page.locator('#app-viewport').evaluate(element => element.inert), true);
        for (let step = 0; step < 16; step++) {
          await page.keyboard.press('Tab');
          assert.ok(await dialog.evaluate(element => element.contains(element.getRootNode().activeElement)), 'Tab stays inside the dialog');
        }
        await dialog.getByRole('button', { name: 'الوضع الداكن', exact: false }).click();
        assert.equal(await page.locator('[data-vortix-host]').getAttribute('data-theme'), 'dark');
        assert.equal(await page.locator('#app-viewport').evaluate(element => element.style.clipPath), 'none');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#app-viewport').evaluate(element => element.inert), false);
        assert.equal(await nav.locator('[data-mobile-nav="more"]').evaluate(element => element.getRootNode().activeElement === element), true);
        await nav.getByRole('button', { name: 'المزيد', exact: true }).tap();
        await dialog.locator('[data-menu-nav="production"]').click();
        await page.waitForURL(url => url.hash === '#/production');
        assert.equal(await dialog.isVisible(), false);
        await nav.getByRole('button', { name: 'الرئيسية', exact: true }).tap();
        await page.locator('#btn-open-transfer').tap();
        await page.setViewportSize({ width, height: 300 });
        const bounds = await page.locator('.v-modal-card').boundingBox();
        assert.ok(bounds.y >= 0 && bounds.y + bounds.height <= 301, 'Sheet stays within a short viewport');
        await page.keyboard.press('Escape');
        await page.setViewportSize({ width, height: 844 });
      } else {
        assert.equal(await page.locator('.v-mobile-nav').isVisible(), false);
        assert.equal(await page.locator('.v-aside-nav').isVisible(), true);
      }
      await page.screenshot({ path: path.join(output, `dashboard-${width}.png`), fullPage: true });
      assert.deepEqual(errors, []);
      await context.close();
      console.log(`PASS navigation, permissions-preserving routes and viewport ${width}`);
    }

    const restricted = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await restricted.addInitScript(() => localStorage.setItem('backoffice_token', 'restricted-ui-review'));
    await restricted.route('**/api/v1/admin/**', route => route.fulfill({ json: route.request().url().endsWith('/me') ? { data: {
      id: 2, name: 'Branch staff', roles: ['staff'], branch_id: 1, permissions: ['inventory.view'], is_active: true,
    } } : { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
    const staffPage = await restricted.newPage();
    await staffPage.goto(office + '/dashboard');
    await staffPage.locator('.v-mobile-nav').waitFor();
    assert.equal(await staffPage.locator('[data-mobile-nav="products"], [data-mobile-nav="categories"]').count(), 0);
    await staffPage.locator('[data-mobile-nav="more"]').click();
    assert.equal(await staffPage.locator('[data-menu-nav="products"], [data-menu-nav="categories"], [data-menu-nav="dealers"]').count(), 0);
    await restricted.close();

    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
    const page = await context.newPage();
    await page.goto(web + '/auth/login');
    await page.getByRole('button', { name: 'تسجيل كتاجر جملة', exact: true }).click();
    await page.locator('#auth-name').fill('Mobile registration');
    await page.locator('#auth-phone').fill('01012345678');
    await page.locator('#auth-password').fill('SecurePassword123');
    await page.locator('#auth-password_confirmation').fill('SecurePassword123');
    await page.getByRole('button', { name: 'التالي: بيانات النشاط', exact: true }).click();
    const upload = page.locator('#auth-documents');
    const document = { name: 'review.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 review') };
    await upload.setInputFiles(document);
    await page.getByRole('button', { name: 'إزالة review.pdf', exact: true }).click();
    assert.equal(await upload.evaluate(element => element.checkValidity()), false, 'Removing the last document restores required validation');
    await upload.setInputFiles(document);
    assert.equal(await page.getByRole('button', { name: 'إزالة review.pdf', exact: true }).count(), 1, 'The same file can be selected again');
    assert.equal(await page.locator('.luxury-stepper').evaluate(element => getComputedStyle(element).display), 'flex');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.screenshot({ path: path.join(output, 'wholesale-mobile.png'), fullPage: true });
    await context.close();
    console.log('PASS restricted navigation, responsive registration and remove/reselect upload validation');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
