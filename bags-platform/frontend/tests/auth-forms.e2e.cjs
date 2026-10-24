const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const web = process.env.E2E_WEB_URL || 'http://localhost:3000';
const api = process.env.E2E_API_URL || 'http://localhost:8000';
const output = path.resolve('test-results/auth-forms');
fs.mkdirSync(output, { recursive: true });
const results = [];
const response = (route, data, status = 200, headers = {}) => route.fulfill({
  status, contentType: 'application/json',
  headers: { 'Access-Control-Allow-Origin': web, 'Access-Control-Expose-Headers': 'Retry-After', ...headers },
  body: JSON.stringify(data),
});
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const run = async (name, test) => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', err => errors.push(err.message));
    try {
      await test(page, context);
      assert.deepEqual(errors, []);
      results.push({ name, passed: true });
      console.log('PASS ' + name);
    } catch (err) {
      results.push({ name, passed: false, error: err.message });
      console.error('FAIL ' + name + ': ' + err.stack);
      await page.screenshot({ path: path.join(output, 'failure-' + results.length + '.png'), fullPage: true }).catch(() => {});
    } finally { await context.close(); }
  };
  const open = async page => {
    await page.goto(web + '/auth/login', { waitUntil: 'domcontentloaded' });
    await page.locator('canvas').waitFor();
  };
  const choose = (page, name) => page.getByRole('button', { name, exact: true }).click();
  const basicDetails = async page => {
    await page.locator('input[name=name]').fill('Auth Form Test');
    await page.locator('input[name=phone]').fill('01088776655');
    await page.locator('input[name=email]').fill('auth-form@example.test');
    await page.locator('input[name=password]').fill('SamplePassword!2026');
    await page.locator('input[name=password_confirmation]').fill('SamplePassword!2026');
  };
  try {
    await run('Inline auth views keep the URL, shared canvas, language and white layout', async page => {
      await open(page);
      const canvas = await page.locator('canvas').elementHandle();
      for (const [name, title, mode] of [
        ['إنشاء حساب جديد', 'إنشاء حساب', 'register'],
        ['نسيت كلمة المرور؟', 'نسيت كلمة المرور؟', 'forgot-password'],
        ['تسجيل كتاجر جملة', 'تسجيل كتاجر جملة', 'wholesale-register'],
      ]) {
        await choose(page, name);
        await page.getByRole('heading', { name: title, exact: true }).waitFor();
        assert.equal(page.url(), web + '/auth/login');
        assert.equal(await page.locator('header, .studio-dock, .mist-backdrop').count(), 0);
        assert.ok(await canvas.isVisible());
        await page.evaluate(() => document.fonts.ready);
        await page.screenshot({ path: path.join(output, mode + '-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(output, mode + '-mobile.png'), fullPage: true });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.setViewportSize({ width: 1440, height: 1000 });
        await choose(page, 'العودة لتسجيل الدخول');
      }
      await choose(page, 'English');
      await choose(page, 'Create an Account');
      await page.getByRole('heading', { name: 'Create an account', exact: true }).waitFor();
      await choose(page, 'Back to sign in');
      assert.equal(page.url(), web + '/auth/login');
    });
    await run('Direct auth URLs use the same frame without the store header', async page => {
      for (const [url, title] of [
        ['/auth/register', 'إنشاء حساب'], ['/auth/forgot-password', 'نسيت كلمة المرور؟'],
        ['/wholesale/register', 'تسجيل كتاجر جملة'], ['/auth/reset-password', 'كلمة مرور جديدة'],
      ]) {
        await page.goto(web + url, { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: title, exact: true }).waitFor();
        assert.equal(await page.locator('header').count(), 0);
        assert.equal(await page.locator('canvas').count(), 1);
      }
    });
    await run('Retail signup submits real endpoint contract, stores the customer session and merges cart header', async page => {
      let payload, cartHeader;
      await page.route(api + '/api/v1/store/auth/register', async route => {
        payload = route.request().postDataJSON();
        cartHeader = route.request().headers()['x-cart-session'];
        await response(route, { token: 'retail-test-token', data: { id: 123, name: 'Auth Form Test' } }, 201);
      });
      await open(page);
      await page.evaluate(() => localStorage.setItem('cart_session', 'test-guest-cart'));
      await choose(page, 'إنشاء حساب جديد');
      await basicDetails(page);
      await choose(page, 'إنشاء حساب');
      await page.waitForURL(web + '/');
      assert.equal(payload.name, 'Auth Form Test');
      assert.equal(payload.phone, '01088776655');
      assert.equal(cartHeader, 'test-guest-cart');
      assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('alfarid_web_auth')).state.destination), 'retail');
      assert.equal(await page.evaluate(() => localStorage.getItem('cart_session')), null);
    });
    await run('Registration validation and phone verification proof are handled inline', async page => {
      let registrationCalls = 0, proof;
      await page.route(api + '/api/v1/store/auth/register', async route => {
        registrationCalls++;
        const payload = route.request().postDataJSON();
        if (!payload.phone_verification_token) return response(route, { errors: { phone_verification_token: ['تحقق من ملكية رقم الهاتف أولًا.'] } }, 422);
        proof = payload.phone_verification_token;
        return response(route, { token: 'phone-test-token', data: { id: 124, name: 'Auth Form Test' } }, 201);
      });
      await page.route(api + '/api/v1/store/auth/phone-verification/send', route => response(route, { message: 'سيتم إرسال رمز التحقق إلى الرقم.' }));
      await page.route(api + '/api/v1/store/auth/phone-verification/verify', route => {
        assert.equal(route.request().postDataJSON().code, '123456');
        return response(route, { phone_verification_token: 'p'.repeat(64) });
      });
      await open(page); await choose(page, 'إنشاء حساب جديد'); await basicDetails(page);
      await choose(page, 'إنشاء حساب');
      await page.getByText('تحقق من ملكية رقم الهاتف أولًا.', { exact: true }).waitFor();
      assert.equal(page.url(), web + '/auth/login');
      await choose(page, 'إرسال رمز التحقق');
      await page.locator('input[name=code]').fill('123456');
      await choose(page, 'تأكيد الرقم');
      await page.getByText('رقم الموبايل مؤكّد', { exact: true }).waitFor();
      await choose(page, 'إنشاء حساب');
      await page.waitForURL(web + '/');
      assert.equal(registrationCalls, 2); assert.equal(proof, 'p'.repeat(64));
    });
    await run('Password recovery and reset complete in the same auth screen', async page => {
      let forgot, reset;
      await page.route(api + '/api/v1/store/auth/forgot-password', route => {
        forgot = route.request().postDataJSON();
        return response(route, { message: 'لو الحساب موجود، أرسلنا رمز الاستعادة إليه.' });
      });
      await page.route(api + '/api/v1/store/auth/reset-password', route => {
        reset = route.request().postDataJSON();
        return response(route, { message: 'تم تغيير كلمة المرور.' });
      });
      await open(page); await choose(page, 'نسيت كلمة المرور؟');
      await page.locator('input[name=identifier]').fill('auth-form@example.test');
      await choose(page, 'إرسال رمز الاستعادة');
      await page.getByText('لو الحساب موجود، أرسلنا رمز الاستعادة إليه.', { exact: true }).waitFor();
      await choose(page, 'لدي رمز الاستعادة');
      await page.locator('input[name=token]').fill('r'.repeat(64));
      await page.locator('input[name=password]').fill('NewPassword!2026');
      await page.locator('input[name=password_confirmation]').fill('NewPassword!2026');
      await choose(page, 'تعيين كلمة المرور');
      await page.getByText('تم تغيير كلمة المرور. يمكنك تسجيل الدخول الآن.', { exact: true }).waitFor();
      assert.equal(forgot.email, 'auth-form@example.test');
      assert.equal(reset.token, 'r'.repeat(64));
      assert.equal(reset.password_confirmation, 'NewPassword!2026');
      assert.equal(page.url(), web + '/auth/login');
      await choose(page, 'العودة لتسجيل الدخول');
    });
    await run('Wholesale registration uploads documents as multipart and opens pending status', async page => {
      let multipart;
      await page.route(api + '/api/v1/wholesale/auth/register', route => {
        assert.match(route.request().headers()['content-type'], /multipart\/form-data; boundary=/);
        multipart = route.request().postDataBuffer().toString();
        return response(route, { token: 'wholesale-test-token', data: { id: 125, name: 'Wholesale Form Test', status: 'pending' } }, 201);
      });
      await page.route(api + '/api/v1/wholesale/auth/application-status', route => response(route, { data: { status: 'pending', rejection_reason: null } }));
      await page.route(api + '/api/v1/auth/me', route => response(route, {
        actor: { type: 'customer', subtype: 'wholesale_pending' },
        redirect: { destination: 'wholesale_pending', path: '/wholesale/application-status' },
        data: { id: 125, name: 'Wholesale Form Test', type: 'retail', status: 'pending' },
      }));
      await open(page); await choose(page, 'تسجيل كتاجر جملة'); await basicDetails(page);
      await choose(page, 'التالي: بيانات النشاط');
      await page.locator('input[name=company_name]').fill('Test Business');
      await page.locator('input[name=tax_number]').fill('123456789');
      await page.locator('input[name=commercial_register]').fill('987654');
      await page.locator('input[name=documents]').setInputFiles({ name: 'register.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nTest document') });
      await choose(page, 'إرسال طلب التسجيل');
      await page.waitForURL(web + '/wholesale/application-status');
      await page.getByText('الحالة: pending', { exact: true }).waitFor();
      assert.ok(multipart.includes('name="company_name"'));
      assert.ok(multipart.includes('name="documents[]"; filename="register.pdf"'));
      assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('alfarid_web_auth')).state.destination), 'wholesale_pending');
    });
    await run('Confirmation mismatch stays on the form and rate-limit errors show Retry-After', async page => {
      await page.route(api + '/api/v1/store/auth/forgot-password', route => response(route, { message: 'Too many attempts' }, 429, { 'retry-after': '42' }));
      await open(page); await choose(page, 'إنشاء حساب جديد'); await basicDetails(page);
      await page.locator('input[name=password_confirmation]').fill('AnotherPassword!');
      await choose(page, 'إنشاء حساب');
      await page.getByText('كلمتا المرور غير متطابقتين.', { exact: true }).waitFor();
      await choose(page, 'العودة لتسجيل الدخول'); await choose(page, 'نسيت كلمة المرور؟');
      await page.locator('input[name=identifier]').fill('01088776655');
      await choose(page, 'إرسال رمز الاستعادة');
      await page.getByText('محاولات كثيرة، حاول بعد 42 ثانية.', { exact: true }).waitFor();
      assert.equal(page.url(), web + '/auth/login');
    });
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2));
  }
  if (results.some(r => !r.passed)) process.exitCode = 1;
})().catch(err => { console.error(err); process.exitCode = 1; });
