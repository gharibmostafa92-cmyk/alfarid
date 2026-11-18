const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const base = process.env.STOREFRONT_URL || 'http://localhost:3003';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/v1/**', route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/forgot-password')) {
        forgotPayloads.push(route.request().postDataJSON());
        return route.fulfill({ json: { message: 'لو الحساب موجود، أرسلنا رمز الاستعادة إليه.' }, headers: { 'access-control-allow-origin': '*' } });
      }
      const data = url.pathname.endsWith('/branding') ? { name: 'VARYA', logo: null } : [];
      return route.fulfill({ json: { data }, headers: { 'access-control-allow-origin': '*' } });
    });
    const forgotPayloads = [];
    await page.goto(`${base}/auth/forgot-password`);
    const identifier = page.getByLabel(/رقم الموبايل أو البريد الإلكتروني/);
    await identifier.waitFor();
    await identifier.fill('someone@example.test');
    await page.getByRole('button', { name: 'إرسال رمز الاستعادة', exact: true }).click();
    await page.getByRole('status').waitFor();
    assert.deepEqual(forgotPayloads, [{ email: 'someone@example.test' }]);
    assert.deepEqual(errors, []);

    const request = context.request;
    const token = `1|${'a'.repeat(40)}`;
    const postAuth = await request.post(`${base}/api/auth`, { headers: { origin: base }, data: { token } });
    assert.ok([404, 405].includes(postAuth.status()));
    assert.equal(postAuth.headers()['set-cookie'], undefined);
    const deleteAuth = await request.delete(`${base}/api/auth`, { headers: { origin: base } });
    assert.ok([404, 405].includes(deleteAuth.status()));
    assert.equal(deleteAuth.headers()['set-cookie'], undefined);
    console.log('Auth audit browser checks passed: email/phone recovery and decommissioned /api/auth endpoint.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
