const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const web = process.env.E2E_WEB_URL || 'http://localhost:3001';

const widgetScript = `
window.turnstile = {
  render(container, options) {
    const button = document.createElement('button');
    button.type = 'button'; button.textContent = 'Complete human verification';
    button.onclick = () => options.callback('fresh-human-token');
    container.appendChild(button);
    window.expireChallenge = options['expired-callback'];
    return 'test-widget';
  },
  remove() {}
};`;

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const wholesale of [false, true]) {
      const context = await browser.newContext({ viewport: { width: 320, height: 850 } });
      const page = await context.newPage();
      await page.clock.install();
      page.setDefaultTimeout(10000);
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      let sends = 0, registration, registrationCalls = 0;
      await context.route('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', route =>
        route.fulfill({ contentType: 'application/javascript', body: widgetScript }));
      await context.route('**/api/v1/**', route => {
        const request = route.request();
        const p = new URL(request.url()).pathname;
        const json = (body, status = 200) => route.fulfill({ status, json: body, headers: {
          'access-control-allow-origin': web, 'access-control-allow-headers': '*', 'access-control-allow-methods': '*',
        } });
        if (request.method() === 'OPTIONS') return json({}, 204);
        if (p.endsWith('/registration-options')) return json({ data: { phone_verification_required: true, turnstile_site_key: 'public-test-key' } });
        if (p.endsWith('/phone-verification/send')) {
          assert.equal(request.postDataJSON().turnstile_token, 'fresh-human-token');
          sends++;
          if (sends === 1) return json({ errors: { turnstile_token: ['انتهى التحقق البشري، حاول مجددًا.'] } }, 422);
          return json({ message: 'تم إرسال الكود.' });
        }
        if (p.endsWith('/phone-verification/verify')) {
          assert.equal(request.postDataJSON().code, '123456');
          return json({ phone_verification_token: 'p'.repeat(64) });
        }
        if (p.endsWith('/auth/register')) {
          if (++registrationCalls === 1) return json({ errors: { phone_verification_token: ['إثبات الهاتف انتهى، أكّد الرقم مجددًا.'] } }, 422);
          registration = wholesale ? request.postDataBuffer().toString() : request.postDataJSON();
          return json({ token: 'session-test-token', data: { id: 12, name: 'Test' } }, 201);
        }
        return json({ data: {} });
      });
      const response = await page.goto(web + (wholesale ? '/wholesale/register' : '/auth/register'), { waitUntil: 'domcontentloaded', timeout: 60000 });
      assert.match(response.headers()['content-security-policy'], /frame-src[^;]*https:\/\/challenges\.cloudflare\.com/);
      await page.locator('input[name=name]').fill('Test');
      await page.locator('input[name=phone]').fill('01012345678');
      await page.locator('input[name=password]').fill('Long-password123');
      await page.locator('input[name=password_confirmation]').fill('Long-password123');
      const send = page.getByRole('button', { name: 'إرسال رمز التحقق', exact: true });
      await send.waitFor();
      assert.equal(await send.isDisabled(), true);
      await page.getByRole('button', { name: 'Complete human verification' }).click();
      await page.evaluate(() => window.expireChallenge());
      await send.and(page.locator(':disabled')).waitFor();
      assert.equal(await send.isDisabled(), true, 'an expired challenge cannot send SMS');
      await page.getByRole('button', { name: 'Complete human verification' }).click();
      await send.click();
      await page.getByText('انتهى التحقق البشري، حاول مجددًا.').waitFor();
      await send.and(page.locator(':disabled')).waitFor();
      assert.equal(await send.isDisabled(), true, 'consumed tokens are cleared after a rejected request');
      await page.getByRole('button', { name: 'Complete human verification' }).click();
      await send.click();
      await page.locator('input[name=code]').fill('١٢٣٤٥٦');
      fs.mkdirSync('test-results/phone-verification', { recursive: true });
      await page.getByRole('region', { name: 'تأكيد رقم الموبايل', exact: true }).screenshot({ path: `test-results/phone-verification/${wholesale ? 'wholesale' : 'retail'}-mobile.png` });
      await page.getByRole('button', { name: 'تأكيد الرقم', exact: true }).click();
      await page.getByText('رقم الموبايل مؤكّد', { exact: true }).waitFor();
      await page.clock.runFor(61000);
      await page.locator('input[name=phone]').fill('01012345679');
      await page.getByText('رقم الموبايل مؤكّد', { exact: true }).waitFor({ state: 'hidden' });
      assert.equal(await page.getByText('رقم الموبايل مؤكّد', { exact: true }).count(), 0, 'changing phone invalidates the proof');
      await page.getByRole('button', { name: 'Complete human verification' }).click();
      await send.click();
      await page.locator('input[name=code]').fill('123456');
      await page.getByRole('button', { name: 'تأكيد الرقم', exact: true }).click();
      await page.getByText('رقم الموبايل مؤكّد', { exact: true }).waitFor();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      if (wholesale) {
        await page.getByRole('button', { name: 'التالي: بيانات النشاط', exact: true }).click();
        await page.locator('input[name=company_name]').fill('Company');
        await page.locator('input[name=tax_number]').fill('123456789');
        await page.locator('input[name=commercial_register]').fill('987654321');
        await page.locator('input[name=documents]').setInputFiles({ name: 'tax.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') });
      }
      await Promise.all([
        page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/auth/register')),
        page.getByRole('button', { name: wholesale ? 'إرسال طلب التسجيل' : 'إنشاء حساب', exact: true }).click(),
      ]);
      await page.getByText('إثبات الهاتف انتهى، أكّد الرقم مجددًا.').waitFor();
      await page.getByRole('button', { name: 'Complete human verification' }).click();
      await send.click();
      await page.locator('input[name=code]').fill('123456');
      await page.getByRole('button', { name: 'تأكيد الرقم', exact: true }).click();
      await page.getByText('رقم الموبايل مؤكّد', { exact: true }).waitFor();
      if (wholesale) await page.getByRole('button', { name: 'التالي: بيانات النشاط', exact: true }).click();
      await Promise.all([
        page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/auth/register')),
        page.getByRole('button', { name: wholesale ? 'إرسال طلب التسجيل' : 'إنشاء حساب', exact: true }).click(),
      ]);
      if (wholesale) assert.match(registration, /name="phone_verification_token"\r\n\r\np{64}/);
      else assert.equal(registration.phone_verification_token, 'p'.repeat(64));
      assert.deepEqual(errors, []);
      await context.close();
      console.log('PASS ' + (wholesale ? 'wholesale' : 'retail') + ' phone verification, retry, expiry and changed number');
    }
    for (const required of [false, true]) {
      const context = await browser.newContext();
      const page = await context.newPage();
      page.setDefaultTimeout(10000);
      let optionsCalls = 0, registration;
      await context.route('**/api/v1/**', route => {
        const request = route.request();
        const p = new URL(request.url()).pathname;
        const json = (body, status = 200) => route.fulfill({ status, json: body, headers: {
          'access-control-allow-origin': web, 'access-control-allow-headers': '*', 'access-control-allow-methods': '*',
        } });
        if (request.method() === 'OPTIONS') return json({}, 204);
        if (p.endsWith('/registration-options')) {
          if (++optionsCalls === 1) return json({ message: 'Unavailable' }, 503);
          return json({ data: { phone_verification_required: required, turnstile_site_key: null } });
        }
        if (p.endsWith('/auth/register')) { registration = request.postDataJSON(); return json({ token: 'optional-test', data: { id: 13 } }, 201); }
        return json({ data: {} });
      });
      await page.goto(web + '/auth/register', { waitUntil: 'domcontentloaded', timeout: 60000 });
      const submit = page.getByRole('button', { name: 'إنشاء حساب', exact: true });
      await page.getByText('تعذر تحميل إعدادات التحقق.').waitFor();
      assert.equal(await submit.isDisabled(), true);
      await page.getByRole('button', { name: 'إعادة المحاولة', exact: true }).click();
      if (required) {
        await page.getByText('التحقق من الموبايل غير متاح حاليًا؛ تواصل مع الدعم.').waitFor();
        assert.equal(await submit.isDisabled(), true);
      } else {
        await page.locator('input[name=name]').fill('Test');
        await page.locator('input[name=phone]').fill('01012345678');
        await page.locator('input[name=password]').fill('Long-password123');
        await page.locator('input[name=password_confirmation]').fill('Long-password123');
        await Promise.all([
          page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/auth/register')),
          submit.click(),
        ]);
        assert.equal(registration.phone_verification_token, undefined);
      }
      await context.close();
      console.log('PASS configuration retry and ' + (required ? 'missing-key protection' : 'optional verification'));
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
