const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:4173';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    await require('./admin-api-routes.cjs')(context);
    await context.route('http://localhost:3000/auth/login', route => route.fulfill({ body: 'Login' }));
    const first = await context.newPage();
    await first.goto(office + '/auth/session');
    await first.evaluate(() => localStorage.setItem('backoffice_token', 'session-test-first'));
    const identity = first.waitForResponse(response => response.url().endsWith('/api/v1/admin/me'));
    await first.goto(office + '/dashboard#/users');
    await identity;
    await first.locator('[data-admin-mock="users"]').waitFor();

    const second = await context.newPage();
    await second.goto(office + '/auth/session');
    const replacement = first.waitForRequest(request => request.url().endsWith('/api/v1/admin/me')
      && request.headers().authorization === 'Bearer session-test-second');
    await second.evaluate(() => localStorage.setItem('backoffice_token', 'session-test-second'));
    await replacement;
    await first.locator('[data-admin-mock="users"]').waitFor();

    const loggedOut = first.waitForURL('http://localhost:3000/auth/login');
    await second.evaluate(() => localStorage.removeItem('backoffice_token'));
    await loggedOut;
    assert.equal(await first.locator('body').innerText(), 'Login');
    console.log('PASS: real browser tabs revalidate a replaced staff session and redirect after remote logout');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
