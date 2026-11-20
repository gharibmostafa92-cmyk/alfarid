const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const office = process.env.E2E_BACKOFFICE_URL || 'http://localhost:5173';
const web = process.env.E2E_WEB_URL || 'http://localhost:3001';
const branch = { id: 1, name: 'Storefront Demo Warehouse', code: 'DEMO-WEB' };
const cashier = {
  id: 42, name: 'Test cashier', roles: ['cashier'], permissions: ['pos.access', 'pos.sales.create', 'pos.sales.refund'],
  branch_id: branch.id, branch, version: 1,
};

test('verified POS sessions distinguish branch access from drawer availability', async (t) => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  t.after(() => browser.close());

  for (const scenario of ['guest', 'expired', 'no-branch', 'branch-revoked', 'profile-denied', 'access-only', 'checkout', 'limited-staff', 'no-drawer', 'empty-drawer', 'funded-drawer']) {
    await t.test(scenario, async () => {
      const page = await browser.newPage();
      page.setDefaultTimeout(12000);
      try {
        const token = scenario === 'guest' ? null : 'pos-test-session';
        const balance = scenario === 'funded-drawer' ? '125.50' : '0.00';
        const drawers = scenario.endsWith('-drawer') && scenario !== 'no-drawer'
          ? [{ id: 8, name: 'Test drawer', balance, busy: false }] : [];
        const openings = [];
        const expenses = [];
        let checkoutShift;
        let logouts = 0;
        await page.route('**/api/v1/**', async (route) => {
          const request = route.request();
          const path = new URL(request.url()).pathname;
          const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
          const reply = (json, status = 200) => route.fulfill({ json, status, headers });
          if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
          if (path.endsWith('/auth/logout')) {
            assert.equal(request.headers().authorization, 'Bearer pos-test-session');
            logouts++;
            return reply({});
          }
          if (path.endsWith('/auth/me')) {
            if (scenario === 'expired') return reply({}, 401);
            if (scenario === 'no-branch') return reply({ code: 'pos_branch_unavailable' }, 403);
            if (scenario === 'profile-denied') return reply({ message: 'User does not have the right permissions.' }, 403);
            if (scenario === 'access-only') return reply({
              actor: { type: 'staff' }, redirect: { path: '/pos' },
              data: { ...cashier, permissions: ['pos.access'] },
            });
            if (scenario === 'limited-staff') return reply({
              actor: { type: 'staff' }, redirect: { path: '/dashboard' },
              data: { ...cashier, roles: ['stock-counter'], permissions: ['pos.access', 'stocktakes.update'] },
            });
            return reply({ actor: { type: 'staff' }, redirect: { path: '/pos' }, data: cashier });
          }
          if (path.endsWith('/admin/users')) throw new Error('Unauthorized page requested user data');
          if (path.endsWith('/admin/me')) throw new Error('POS bootstrap requested the admin profile');
          if (path.endsWith('/bootstrap')) {
            if (scenario === 'branch-revoked') return reply({ code: 'pos_branch_unavailable' }, 403);
            assert.notEqual(scenario, 'access-only', 'Access-only staff must not load sales');
            checkoutShift = scenario === 'checkout' ? {
              id: 24, shift_number: 'POS-24', status: 'open', opened_at: new Date().toISOString(),
              treasury: { id: 8, name: 'Test drawer' }, branch, opening_cash: 20,
            } : null;
            return reply({ data: { branch, user: cashier, drawers, shift: checkoutShift, categories: [{ id: 'all', name: 'الكل' }], settings: { max_discount_percent: 100 } } });
          }
          if (path.endsWith('/catalog') && scenario === 'checkout') {
            return reply({ data: [{ id: 1, sku: 'BAG-1', name: 'Test bag', available: 4, unit_price: 160 }] });
          }
          if (scenario === 'checkout' && path.endsWith('/shifts/current/summary')) {
            return reply({ data: { ...checkoutShift, expected_closing_cash: '180.00', cash_sales_total: 160, cash_expenses_total: 20 } });
          }
          if (path.endsWith('/pos/me/password')) {
            assert.equal(request.method(), 'PUT');
            assert.equal(request.postDataJSON().expected_version, 1);
            return reply({ data: { version: 2 } });
          }
          if (path.endsWith('/sales/expenses')) {
            assert.ok(request.headers()['idempotency-key']);
            expenses.push(request.postDataJSON());
            if (expenses.length === 1) return reply({ message: 'User does not have the right permissions.' }, 403);
            return reply({ data: { id: 1 } }, 201);
          }
          if (path.endsWith('/sales') && request.method() === 'POST') {
            return reply({ data: { id: 66, order_number: 'SALE-66' } }, 201);
          }
          if (path.endsWith('/sales/quote')) {
            return reply({ data: { lines: [{ product_variant_id: 1, qty: 1, unit_price: 160, line_total: 160, available: 4 }], subtotal: 160, discount_total: 0, grand_total: 160 } });
          }
          if (path.endsWith('/treasuries/available')) return reply({ data: drawers });
          if (path.endsWith('/shifts/current')) return reply({ data: null });
          if (path.endsWith('/shifts/open')) {
            openings.push(request.postDataJSON());
            return reply({ message: 'Fixture drawer conflict' }, 409);
          }
          return reply({ data: [] });
        });
        if (token) await page.addInitScript(({ origin, value }) => {
          if (location.origin !== origin || sessionStorage.getItem('seeded')) return;
          localStorage.setItem('backoffice_token', value);
          sessionStorage.setItem('seeded', '1');
        }, { origin: office, value: token });
        await page.goto(office + (['guest', 'limited-staff'].includes(scenario) ? '/dashboard#/users' : '/pos'));
        if (scenario === 'guest' || scenario === 'expired') {
          await page.waitForURL(web + '/auth/login');
          assert.equal(logouts, 0);
          return;
        }
        if (['no-branch', 'branch-revoked', 'profile-denied', 'access-only'].includes(scenario)) {
          const message = scenario === 'access-only' ? /اطلب صلاحية البيع/
            : scenario === 'profile-denied' ? /تعذر التحقق من صلاحية/
            : /فرع نشط|الفرع/;
          for (let reloads = 0; reloads < 2; reloads++) {
            await page.getByText(message).first().waitFor();
            assert.equal(new URL(page.url()).pathname, '/pos');
            assert.equal(await page.evaluate(() => localStorage.getItem('backoffice_token')), token);
            if (reloads === 0) await page.reload();
          }
          assert.equal(logouts, 0);
          return;
        }
        if (scenario === 'checkout') {
          await page.setViewportSize({ width: 390, height: 760 });
          await page.getByRole('button', { name: /Test bag/ }).waitFor();
          await page.reload();
          const settings = page.getByRole('button', { name: 'إعدادات الحساب' });
          const account = page.getByRole('dialog', { name: 'إعدادات الحساب' });
          for (const viewport of [{ width: 390, height: 760 }, { width: 1440, height: 900 }]) {
            await page.setViewportSize(viewport);
            await settings.click();
            await account.getByRole('heading', { name: 'إعدادات الحساب' }).waitFor();
            await account.getByText('Test cashier').waitFor();
            const bounds = await account.boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= viewport.width);
            assert(bounds.y >= 0 && bounds.y + bounds.height <= viewport.height);
            assert(await account.evaluate((el) => el.scrollWidth <= el.clientWidth + 1));
            const current = account.getByLabel('كلمة المرور الحالية', { exact: true });
            await current.fill('Current-password1');
            await account.getByRole('button', { name: 'إظهار كلمة المرور الحالية' }).click();
            assert.equal(await current.getAttribute('type'), 'text');
            await account.getByRole('button', { name: 'إخفاء كلمة المرور الحالية' }).click();
            await account.getByLabel('كلمة المرور الجديدة', { exact: true }).fill('Replacement1-pass');
            await account.getByLabel('تأكيد كلمة المرور الجديدة', { exact: true }).fill('Replacement1-pass');
            if (viewport.width === 1440) {
              await account.getByRole('button', { name: 'حفظ كلمة المرور' }).click();
              await account.getByRole('status').getByText('تم تغيير كلمة المرور.').waitFor();
            }
            await page.keyboard.press('Escape');
            assert.equal(await account.count(), 0);
            assert(await settings.evaluate(el => el === document.activeElement));
          }
          await page.setViewportSize({ width: 390, height: 760 });
          await page.getByRole('button', { name: 'مصروف من الدرج' }).click();
          const expense = page.getByRole('dialog', { name: 'مصروف من الدرج' });
          await expense.getByLabel('المبلغ بالجنيه').fill('٢٠٫٥٠');
          await expense.getByLabel('بيان المصروف').fill('نقل بضاعة');
          await expense.getByRole('button', { name: 'تسجيل المصروف' }).click();
          await expense.getByRole('alert').getByText('حسابك لا يملك صلاحية تنفيذ هذه العملية.').waitFor();
          await expense.getByRole('button', { name: 'تسجيل المصروف' }).click();
          await page.getByText('تم تسجيل المصروف من الدرج').waitFor();
          assert.deepEqual(expenses, Array(2).fill({ amount: 20.5, category: 'نقل بضاعة', notes: null }));
          await page.getByRole('button', { name: 'مرتجع أو استبدال' }).click();
          await page.getByRole('heading', { name: 'مرتجع / استبدال' }).waitFor();
          await page.keyboard.press('Escape');
          await page.getByRole('button', { name: /وردية #POS-24/ }).click();
          const closing = page.getByRole('dialog', { name: 'إغلاق الوردية وجرد النقدية' });
          await closing.getByText('مصروفات الدرج').waitFor();
          assert(await closing.evaluate((el) => el.scrollWidth <= el.clientWidth + 1));
          const closeBounds = await closing.boundingBox();
          assert(closeBounds.x >= 0 && closeBounds.x + closeBounds.width <= 391, JSON.stringify(closeBounds));
          await closing.getByRole('button', { name: 'رجوع للبيع' }).click();
          await page.getByRole('button', { name: /Test bag/ }).click();
          await page.getByRole('button', { name: /إتمام الدفع/ }).click();
          const checkout = page.getByRole('dialog', { name: 'الدفع وطرق التحصيل' });
          await checkout.waitFor();
          for (const method of ['نقدي', 'بطاقة / فيزا', 'دفع مختلط']) {
            const option = checkout.getByRole('button', { name: method, exact: true });
            await option.click();
            assert.equal(await option.getAttribute('aria-pressed'), 'true');
            assert(await checkout.evaluate((el) => el.scrollWidth <= el.clientWidth + 1));
            assert(await checkout.getByRole('button', { name: /تأكيد الدفع/ }).isVisible());
          }
          await checkout.getByRole('button', { name: 'بطاقة / فيزا', exact: true }).click();
          await checkout.getByRole('button', { name: /تأكيد الدفع/ }).click();
          const receipt = page.getByRole('dialog', { name: 'إيصال الدفع' });
          await receipt.getByRole('heading', { name: 'تم تسجيل البيع بنجاح' }).waitFor();
          await receipt.getByText('معاينة الإيصال').click();
          assert(await receipt.evaluate((el) => el.scrollWidth <= el.clientWidth + 1));
          await receipt.getByRole('button', { name: 'فاتورة جديدة' }).click();
          assert.equal(await receipt.count(), 0);
          return;
        }
        if (scenario === 'limited-staff') {
          await page.getByRole('alert').getByText(/ليس لديك صلاحية/).waitFor();
          await page.goto(office + '/dashboard');
          await page.getByRole('link', { name: 'عرض المتجر' }).waitFor();
          assert.equal(await page.getByRole('link', { name: 'عرض المتجر' }).getAttribute('href'), web + '/');
          await page.goto(web + '/auth/login');
          await page.waitForURL(office + '/dashboard');
          assert.equal(await page.locator('input[name=login]').count(), 0);
          return;
        }
        await page.locator('.gate').getByText(branch.name).waitFor();
        const open = page.getByRole('button', { name: /فتح الوردية وبدء البيع/ });
        const cash = page.locator('.gate [data-money-input] input');
        if (scenario === 'no-drawer') {
          await page.getByText(/لا يوجد درج نقدية نشط/).waitFor();
          assert(await cash.isDisabled());
          assert(await open.isDisabled());
          assert.deepEqual(openings, []);
          return;
        }
        if (scenario === 'funded-drawer') {
          assert.equal(await cash.getAttribute('readonly'), '');
          assert.equal(Number(await cash.inputValue()), 125.5);
        } else {
          assert(await open.isDisabled());
          for (const invalid of ['-5', '10.555', '10000000']) {
            await cash.fill(invalid);
            await open.click();
            await page.getByText(/مبلغ العهدة غير صالح/).waitFor();
          }
          assert.deepEqual(openings, []);
          await cash.fill('١٢٣٫٥٠');
        }
        await open.click();
        await page.getByText('Fixture drawer conflict').waitFor();
        assert.deepEqual(openings, [{ treasury_id: 8, opening_cash: scenario === 'funded-drawer' ? 125.5 : 123.5 }]);
      } finally {
        await page.close();
      }
    });
  }
});
