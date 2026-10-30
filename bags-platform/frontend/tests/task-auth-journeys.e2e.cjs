const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

// Started by backend/tests/Browser/TaskAuthJourneyTest.php. No API fixtures.
const web = process.env.STOREFRONT_URL || 'http://localhost:3003';
const office = process.env.BACKOFFICE_URL || 'http://localhost:5174';
const api = process.env.API_URL || 'http://localhost:8000';
const phone = '01022223333';
const password = 'Browser-password12';
let browser;
before(async () => {
  assert.ok(process.env.AUTH_BROWSER_SMS, 'Run via the isolated Laravel browser test harness');
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge', headless: true });
});
after(async () => { await browser?.close(); });

async function withPage(run) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await run(page, context);
    assert.deepEqual(errors, []);
  } catch (error) {
    const output = path.resolve(__dirname, '../../../test-results/task-audit');
    fs.mkdirSync(output, { recursive: true });
    await page.screenshot({ path: path.join(output, `auth-journey-${new URL(page.url()).pathname.replace(/\W/g, '_')}.png`), fullPage: true });
    console.log('Failed journey URL:', page.url().split('#')[0]);
    console.log('Page alerts:', await page.getByRole('alert').allTextContents());
    throw error;
  } finally { await context.close(); }
}

async function submit(page, endpoint, status) {
  const response = page.waitForResponse(response => new URL(response.url()).pathname === endpoint && response.request().method() === 'POST');
  await page.locator('form button[type=submit]').click();
  const received = await response;
  if (received.status() !== status) assert.fail(`${endpoint}: ${received.status()} ${await received.text()}`);
  return received;
}

async function login(page, identifier, secret) {
  await page.goto(`${web}/auth/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !document.querySelector('form button[type=submit]').disabled);
  await page.locator('#login').fill(identifier);
  await page.locator('#password').fill(secret);
  return submit(page, '/api/v1/auth/login', 200);
}

async function logout(page) {
  await page.getByRole('button', { name: 'User Profile Menu', exact: true }).click();
  const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/logout' && response.request().method() === 'POST');
  await page.getByRole('menuitem', { name: /Sign out/i }).click();
  assert.equal((await response).status(), 204);
  await page.waitForURL(`${web}/`, { waitUntil: 'domcontentloaded' });
}

test('task 26: retail registration, logout, phone login and logout use real API', async () => withPage(async page => {
  await page.goto(`${web}/auth/register`, { waitUntil: 'domcontentloaded' });
  await page.locator('.luxury-brand-btn:enabled').waitFor();
  await page.locator('#auth-name').fill('Browser retail customer');
  await page.locator('#auth-phone').fill(phone);
  await page.locator('#auth-password').fill(password);
  await page.locator('#auth-password_confirmation').fill(password);
  await submit(page, '/api/v1/store/auth/register', 201);
  await page.waitForURL(`${web}/shop`, { waitUntil: 'domcontentloaded' });
  await logout(page);
  await login(page, phone, password);
  await page.waitForURL(`${web}/shop`, { waitUntil: 'domcontentloaded' });
  await logout(page);
}));

test('task 26: staff handoff crosses origins, erases fragment and exchanges once in StrictMode', async () => withPage(async (page, context) => {
  let exchanges = 0;
  const callbackUrls = [];
  context.on('request', request => {
    if (new URL(request.url()).pathname === '/api/v1/auth/handoff/exchange' && request.method() === 'POST') exchanges++;
    if (new URL(request.url()).pathname === '/auth/callback') callbackUrls.push(request.url());
  });
  await login(page, 'browser-staff@example.test', password);
  await page.waitForURL(`${office}/dashboard`, { waitUntil: 'domcontentloaded' });
  assert.equal(new URL(page.url()).hash, '');
  assert.equal(exchanges, 1);
  assert.ok(callbackUrls.length > 0);
  assert.ok(callbackUrls.every(url => !url.includes('code=')));
  // Only inspect presence of credentials created by this isolated fixture.
  assert.equal(await page.evaluate(() => !!localStorage.getItem('backoffice_token')), true);
  const storePage = await context.newPage();
  await storePage.goto(`${web}/auth/forgot-password`);
  assert.equal(await storePage.evaluate(() => JSON.parse(localStorage.getItem('alfarid_web_auth') || 'null')?.state?.token ?? null), null);
}));

test('task 26: wholesale document registration reaches real pending application status', async () => withPage(async page => {
  await page.goto(`${web}/wholesale/register`, { waitUntil: 'domcontentloaded' });
  await page.locator('.luxury-brand-btn:enabled').waitFor();
  await page.locator('#auth-name').fill('Browser wholesale customer');
  await page.locator('#auth-phone').fill('01033334444');
  await page.locator('#auth-password').fill(password);
  await page.locator('#auth-password_confirmation').fill(password);
  await page.locator('form button[type=submit]').click();
  await page.locator('#auth-company_name').fill('Browser company');
  await page.locator('#auth-tax_number').fill('123456789');
  await page.locator('#auth-commercial_register').fill('987654');
  await page.locator('#auth-documents').setInputFiles({ name: 'registration.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF') });
  await submit(page, '/api/v1/wholesale/auth/register', 201);
  await page.waitForURL(`${web}/wholesale/application-status`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('heading', { name: 'حالة طلب الاعتماد' }).waitFor();
  await page.locator('.as-status[data-s=pending]').waitFor();
  await page.getByText('registration.pdf', { exact: true }).waitFor();
}));

test('task 26: fake SMS recovery resets the real account and the new password logs in', async () => withPage(async (page, context) => {
  const recoveryPhone = '01044445555';
  const created = await context.request.post(`${api}/api/v1/store/auth/register`, {
    data: { name: 'Browser recovery customer', phone: recoveryPhone, password },
  });
  assert.equal(created.status(), 201);
  await page.goto(`${web}/auth/forgot-password`, { waitUntil: 'domcontentloaded' });
  await page.locator('.luxury-brand-btn:enabled').waitFor();
  await page.locator('#auth-identifier').fill(recoveryPhone);
  await submit(page, '/api/v1/store/auth/forgot-password', 200);
  const sms = fs.readFileSync(process.env.AUTH_BROWSER_SMS, 'utf8');
  const code = sms.match(/[A-Za-z0-9]{64}/)?.[0];
  assert.ok(code, 'The fake notification channel captured a recovery code');
  await page.getByRole('button', { name: 'لدي رمز الاستعادة', exact: true }).click();
  await page.locator('#auth-token').fill(code);
  await page.locator('#auth-password').fill('Replacement-password34');
  await page.locator('#auth-password_confirmation').fill('Replacement-password34');
  await submit(page, '/api/v1/store/auth/reset-password', 200);
  await page.getByRole('status').filter({ hasText: 'تم تغيير كلمة المرور' }).waitFor();
  await login(page, recoveryPhone, 'Replacement-password34');
  await page.waitForURL(`${web}/shop`, { waitUntil: 'domcontentloaded' });
}));
