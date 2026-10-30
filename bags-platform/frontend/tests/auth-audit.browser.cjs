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
      const data = url.pathname.endsWith('/branding') ? { name: 'Alfarid', logo: null } : [];
      return route.fulfill({ json: { data }, headers: { 'access-control-allow-origin': '*' } });
    });
    await page.goto(`${base}/auth/forgot-password`);
    const phone = page.getByLabel(/رقم الموبايل المسجل/);
    await phone.waitFor();
    assert.equal(await phone.getAttribute('inputmode'), 'tel');
    await phone.fill('someone@example.test');
    await page.getByRole('button', { name: 'إرسال رمز الاستعادة', exact: true }).click();
    await page.getByRole('alert').filter({ hasText: 'استخدم رقم الموبايل المسجل' }).waitFor();
    assert.deepEqual(errors, []);

    const request = context.request;
    const token = `1|${'a'.repeat(40)}`;
    const plain = await request.post(`${base}/api/auth`, {
      headers: { 'content-type': 'text/plain' }, data: JSON.stringify({ token }),
    });
    assert.equal(plain.status(), 415);
    assert.equal(plain.headers()['set-cookie'], undefined);
    const crossSite = await request.post(`${base}/api/auth`, {
      headers: { origin: 'https://attacker.example' }, data: { token },
    });
    assert.equal(crossSite.status(), 403);
    const invalidExpiry = await request.post(`${base}/api/auth`, { data: { token, expiresIn: 999999999 } });
    assert.equal(invalidExpiry.status(), 400);
    const valid = await request.post(`${base}/api/auth`, {
      headers: { origin: base }, data: { token, expiresIn: 120 },
    });
    assert.equal(valid.status(), 200);
    assert.match(valid.headers()['set-cookie'], /Max-Age=120/i);
    assert.match(valid.headers()['set-cookie'], /HttpOnly/i);
    console.log('Auth audit browser checks passed: phone recovery, JSON/origin checks, token expiry and cookies.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
