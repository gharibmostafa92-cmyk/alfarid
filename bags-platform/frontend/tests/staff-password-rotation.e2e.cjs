const assert = require('node:assert/strict');
const { test } = require('node:test');
const { chromium } = require('playwright');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:4173';

test('a cashier must rotate the temporary password before opening POS', async (t) => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width: 320, height: 720 } });
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  const cashier = {
    id: 47, name: 'Cashier', email: 'cashier@example.test', branch_id: 2,
    branch: { id: 2, name: 'Test branch' }, roles: [{ id: 3, name: 'cashier' }],
    permissions: ['pos.access', 'pos.sales.create'], version: 7, must_change_password: true,
  };
  const rotationRequests = [];
  let posRequests = 0;
  await page.route('**/*', (route) => new URL(route.request().url()).origin === new URL(office).origin
    ? route.continue()
    : route.abort());
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const headers = {
      'access-control-allow-origin': '*',
      'access-control-allow-headers': '*',
      'access-control-allow-methods': 'GET, POST, PUT, OPTIONS',
    };
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
    if (url.pathname === '/api/v1/auth/handoff/exchange') {
      return route.fulfill({ headers, json: { token: 'cashier-test-token', data: cashier, redirect: { path: '/pos' } } });
    }
    if (url.pathname === '/api/v1/admin/me') return route.fulfill({ headers, json: { data: cashier } });
    if (url.pathname === '/api/v1/admin/me/password') {
      rotationRequests.push({ method: request.method(), token: request.headers().authorization, body: request.postDataJSON() });
      if (rotationRequests.length === 1) {
        return route.fulfill({
          status: 422, headers, json: { errors: { current_password: ['كلمة المرور المؤقتة غير صحيحة.'] } },
        });
      }
      Object.assign(cashier, { version: 8, must_change_password: false });
      return route.fulfill({ headers, json: { data: cashier } });
    }
    if (url.pathname.startsWith('/api/v1/pos/')) posRequests++;
    if (url.pathname === '/api/v1/pos/bootstrap') {
      return route.fulfill({ headers, json: { data: {
        user: cashier, branch: cashier.branch, shift: null, drawers: [], categories: [],
        settings: { max_discount_percent: 0, currency: 'EGP' },
      } } });
    }
    return route.fulfill({ headers, json: { data: [] } });
  });

  await page.goto(office + '/auth/callback#code=test-cashier-handoff');
  await page.waitForURL('**/pos');
  const current = page.getByLabel('كلمة المرور الحالية', { exact: true });
  const next = page.getByLabel('كلمة المرور الجديدة', { exact: true });
  const submit = page.getByRole('button', { name: 'حفظ كلمة المرور' });
  await current.waitFor({ timeout: 5000 });
  assert.equal(posRequests, 0);
  assert.ok(await current.evaluate((input) => input === document.activeElement));

  await current.fill('wrong-temporary-123');
  await next.fill('new-password-456');
  await submit.click();
  await page.getByRole('alert').getByText('كلمة المرور المؤقتة غير صحيحة.').waitFor();
  assert.equal(posRequests, 0);

  await current.fill('temporary-123');
  await submit.click();
  await current.waitFor({ state: 'detached' });
  await page.getByText('لا توجد وردية مفتوحة باسمك. افتح وردية لبدء البيع.').waitFor();
  assert.equal(new URL(page.url()).pathname, '/pos');
  assert.deepEqual(rotationRequests[1], {
    method: 'PUT',
    token: 'Bearer cashier-test-token',
    body: { current_password: 'temporary-123', password: 'new-password-456', expected_version: 7 },
  });
  assert.deepEqual(pageErrors, []);
});
