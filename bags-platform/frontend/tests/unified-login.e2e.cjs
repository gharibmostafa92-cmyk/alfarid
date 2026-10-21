const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const web = process.env.E2E_WEB_URL || 'http://localhost:3000';
const office = process.env.E2E_BACKOFFICE_URL || 'http://localhost:5173';
const api = process.env.E2E_API_URL || 'http://localhost:8000';
const password = process.env.UNIFIED_DEMO_PASSWORD;
if (!password) throw new Error('Set UNIFIED_DEMO_PASSWORD after running UnifiedLoginDemoSeeder.');
const output = path.resolve('test-results/unified-login');
fs.mkdirSync(output, { recursive: true });
const results = [];
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const context = () => browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const check = async (name, run) => {
    if (process.env.E2E_TEST_FILTER && !new RegExp(process.env.E2E_TEST_FILTER).test(name)) return;
    const ctx = await context();
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', err => errors.push(err.message));
    try {
      await run(page, ctx);
      assert.deepEqual(errors, [], 'Browser runtime errors');
      results.push({ name, passed: true });
      console.log('PASS ' + name);
    } catch (err) {
      results.push({ name, passed: false, error: err.message });
      await page.screenshot({ path: path.join(output, `failure-${results.length}.png`), fullPage: true }).catch(() => {});
      console.error('FAIL ' + name + ': ' + err.stack);
    } finally { await ctx.close(); }
  };
  const login = async (page, id, target, suppliedPassword = password) => {
    let payload;
    await page.route(api + '/api/v1/auth/login', async route => {
      const response = await route.fetch();
      payload = { status: response.status(), body: await response.json() };
      await route.fulfill({ response });
    });
    await page.goto(web + '/auth/login' + (target ? '?redirect=' + encodeURIComponent(target) : ''));
    await page.locator('form button[type="submit"]:not([disabled])').waitFor({ state: 'visible' });
    await page.locator('input[name=login]').fill(id.includes('@') || /^\+?\d/.test(id) ? id : `unified-${id}@example.test`);
    await page.locator('input[name=password]').fill(suppliedPassword);
    const response = page.waitForResponse(r => r.url() === api + '/api/v1/auth/login' && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
    await response;
    return payload;
  };
  const awaitPath = async (page, origin, pathname) => {
    try {
      await page.waitForURL(u => u.origin === origin && u.pathname === pathname, { timeout: 30000, waitUntil: 'domcontentloaded' });
    } catch (err) {
      if (!err.message.includes('ERR_ABORTED')) throw err;
      await page.waitForURL(u => u.origin === origin && u.pathname === pathname, { timeout: 30000, waitUntil: 'domcontentloaded' });
    }
  };
  const webSession = (page) => page.evaluate(() => JSON.parse(localStorage.getItem('alfarid_web_auth') || '{}').state);
  const assertRevoked = async (ctx, token) => {
    const response = await ctx.request.get(api + '/api/v1/auth/me', { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' } });
    assert.equal(response.status(), 401);
  };
  try {
    await check('Login artwork, language, fullscreen Birkin-only desktop and mobile', async (page) => {
      await page.goto(web + '/auth/login');
      await page.locator('canvas').waitFor();
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await page.locator('header, .studio-dock, .mist-backdrop, .mist-overlay').count(), 0);
      assert.equal(await page.locator('canvas').count(), 1);
      assert.equal(await page.locator('.luxury-auth-page').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)');
      const bounds = await page.locator('.luxury-auth-page').boundingBox();
      assert.equal(bounds.x, 0);
      assert.equal(bounds.y, 0);
      assert.equal(bounds.width, 1440);
      assert.ok(bounds.height >= 1000);
      await page.screenshot({ path: path.join(output, 'login-desktop.png'), fullPage: true });
      await page.getByRole('button', { name: 'English', exact: true }).click();
      await page.getByText('Email or mobile number', { exact: true }).waitFor();
      await page.getByRole('button', { name: 'العربية', exact: true }).click();
      await page.setViewportSize({ width: 390, height: 844 });
      await page.screenshot({ path: path.join(output, 'login-mobile.png'), fullPage: true });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    });
    await check('Admin handoff, real identity, permissions, reload and logout revocation', async (page, ctx) => {
      const res = await login(page, '  UNIFIED-ADMIN@EXAMPLE.TEST  ');
      assert.equal(res.status, 200);
      assert.equal(res.body.token, null);
      await awaitPath(page, office, '/dashboard');
      await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
      assert.equal(res.body.data.name, 'مدير اختبار الدخول');
      await page.reload();
      await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
      const token = await page.evaluate(() => localStorage.getItem('backoffice_token'));
      assert.ok(token);
      await page.screenshot({ path: path.join(output, 'admin-session.png'), fullPage: true });
      await page.locator('#side-logout-btn').click();
      await awaitPath(page, web, '/auth/login');
      await assertRevoked(ctx, token);
      assert.equal(await page.evaluate(() => localStorage.getItem('backoffice_token')), null);
      assert.ok(!(await webSession(page))?.token);
    });
    await check('Cashier dashboard landing and POS access', async (page) => {
      await login(page, 'cashier');
      await awaitPath(page, office, '/dashboard');
      await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
      await page.goto(office + '/pos');
      await page.getByRole('heading', { name: 'نقطة البيع', exact: true }).waitFor();
      await page.goto(office + '/dashboard');
      await awaitPath(page, office, '/dashboard');
      await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
      await page.locator('#side-logout-btn').click();
      await awaitPath(page, web, '/auth/login');
    });
    for (const [kind, destination] of [['supplier', '/supplier'], ['approved', '/wholesale'], ['pending', '/wholesale/application-status'], ['rejected', '/wholesale/application-status'], ['retail', '/']]) {
      await check(kind + ' destination, persisted session and logout revocation', async (page, ctx) => {
        const res = await login(page, kind);
        assert.equal(res.status, 200);
        await awaitPath(page, web, destination);
        const logoutButton = kind === 'retail'
          ? page.getByRole('button', { name: 'تسجيل الخروج', exact: true }).first()
          : page.locator('#side-logout-btn');
        await logoutButton.waitFor();
        if (kind !== 'retail') await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
        assert.ok((await webSession(page)).token);
        await page.reload();
        await logoutButton.waitFor();
        await logoutButton.click();
        await awaitPath(page, web, '/auth/login');
        await assertRevoked(ctx, res.body.token);
      });
    }
    for (const phone of ['01070000001', '+201070000001']) {
      await check('Retail normalized phone ' + phone, async page => {
        await login(page, phone);
        await awaitPath(page, web, '/');
      });
    }
    await check('Retail checkout redirect', async page => {
      const res = await login(page, 'retail', '/checkout');
      assert.equal(res.body.redirect.path, '/checkout');
      await awaitPath(page, web, '/checkout');
    });
    await check('Wrong password stays on login with inline error', async page => {
      const res = await login(page, 'retail', null, 'incorrect-password');
      assert.equal(res.status, 401);
      await page.getByText('بيانات الدخول غير صحيحة.', { exact: true }).waitFor();
      assert.equal(new URL(page.url()).pathname, '/auth/login');
    });
    await check('Suspension reason is shown', async page => {
      const res = await login(page, 'suspended');
      assert.equal(res.status, 403);
      await page.getByText('هذا الحساب موقوف. السبب: حساب اختبار موقوف', { exact: true }).waitFor();
    });
    await check('Unauthenticated admin redirects to unified login', async page => {
      await page.goto(office + '/dashboard');
      await awaitPath(page, web, '/auth/login');
    });
    await check('Replayed callback returns handoff error and staff token stays off web origin', async page => {
      const res = await login(page, 'admin');
      await awaitPath(page, office, '/dashboard');
      await page.getByRole('heading', { name: 'Good morning, Code XR', exact: true }).waitFor();
      await page.goto(office + '/auth/callback#code=' + encodeURIComponent(res.body.handoff_code));
      await page.waitForURL(web + '/auth/login?error=handoff');
      await page.getByText('انتهت صلاحية رابط الدخول، سجّل الدخول مرة أخرى', { exact: true }).waitFor();
      assert.equal(await page.evaluate(() => localStorage.getItem('backoffice_token')), null);
      assert.ok(!(await webSession(page))?.token);
    });
    await check('Customer is redirected away from supplier portal', async page => {
      await login(page, 'approved');
      await awaitPath(page, web, '/wholesale');
      await page.goto(web + '/supplier');
      await awaitPath(page, web, '/wholesale');
    });
    await check('Sixth failed attempt displays Retry-After', async page => {
      await page.goto(web + '/auth/login');
      await page.locator('input[name=login]').fill('unified-rate-limit@example.test');
      await page.locator('input[name=password]').fill('incorrect-password');
      for (let i = 0; i < 6; i++) {
        const response = page.waitForResponse(r => r.url() === api + '/api/v1/auth/login' && r.request().method() === 'POST');
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
        const res = await response;
        assert.equal(res.status(), i === 5 ? 429 : 401);
        if (i === 5) assert.ok(res.headers()['retry-after']);
        await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).waitFor({ state: 'visible' });
      }
      await page.getByText(/محاولات كثيرة، حاول بعد/).waitFor();
    });
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
  }
  if (results.some(r => !r.passed)) process.exitCode = 1;
})().catch(err => { console.error(err); process.exitCode = 1; });
