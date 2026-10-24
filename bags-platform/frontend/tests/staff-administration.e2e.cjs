const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:4173';

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    await context.addInitScript(() => localStorage.setItem('backoffice_token', 'followup-review'));
    const state = await require('./admin-api-routes.cjs')(context);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(office + '/dashboard#/users');
    const users = page.locator('[data-admin-mock="users"]');
    const staff = state.users.data.find(user => user.email === 'staff@example.test');
    const row = users.locator('tr').filter({ hasText: staff.email });
    await row.getByTitle('تعديل', { exact: true }).click();
    staff.name = 'تعديل من مدير آخر';
    staff.version++;
    await users.getByPlaceholder('مثال: محمد أحمد علي').fill('بيانات قديمة');
    await users.getByRole('button', { name: 'حفظ التعديلات', exact: true }).click();
    await users.getByText('تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.', { exact: true }).waitFor();
    await row.getByText('تعديل من مدير آخر', { exact: true }).waitFor();
    assert.equal(staff.name, 'تعديل من مدير آخر');
    assert.equal(state.requests.findLast(request => request.method === 'PATCH').body.expected_version, 0);

    const me = state.users.data.find(user => user.id === state.me.data.id);
    await users.locator('tr').filter({ hasText: me.email }).getByTitle('تعديل', { exact: true }).click();
    await users.getByPlaceholder('name@company.com').fill('new-me@example.test');
    await users.getByPlaceholder('أدخل كلمة المرور الحالية').waitFor();
    const before = state.requests.filter(request => request.method === 'PATCH').length;
    await users.getByRole('button', { name: 'حفظ التعديلات', exact: true }).click();
    await users.getByText('كلمة المرور الحالية مطلوبة لتغيير بيانات الدخول', { exact: true }).waitFor();
    assert.equal(state.requests.filter(request => request.method === 'PATCH').length, before);
    await users.getByPlaceholder('أدخل كلمة المرور الحالية').fill('Current-password12');
    await users.getByRole('button', { name: 'حفظ التعديلات', exact: true }).click();
    await users.getByRole('button', { name: 'تأكيد الحفظ', exact: true }).click();
    await users.getByText('تم حفظ التعديلات', { exact: true }).waitFor();
    const selfPatch = state.requests.findLast(request => request.method === 'PATCH').body;
    assert.equal(selfPatch.current_password, 'Current-password12');
    assert.equal(selfPatch.expected_version, me.version - 1);
    assert.equal('roles' in selfPatch, false);

    await page.goto(office + '/dashboard#/roles');
    await page.reload();
    const roles = page.locator('[data-admin-mock="roles"]');
    const sharedRole = state.roles.data.find(role => !role.is_system);
    await roles.getByText(sharedRole.name, { exact: true }).first().click();
    await roles.getByPlaceholder('مثال: مشرف مبيعات').fill('نسخة قديمة من الدور');
    sharedRole.name = 'دور عدله مدير آخر';
    sharedRole.version++;
    await roles.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
    await roles.getByText('تم تعديل السجل بواسطة مستخدم آخر؛ أعد التحميل قبل الحفظ.', { exact: true }).waitFor();
    await roles.getByText('دور عدله مدير آخر', { exact: true }).first().waitFor();
    assert.equal(sharedRole.name, 'دور عدله مدير آخر');
    assert.equal(state.requests.findLast(request => request.method === 'PATCH').body.expected_version, 0);

    state.roles.data.push({ id: 9999, name: 'cashier', guard_name: 'staff', is_builtin: true,
      is_system: false, permissions: ['pos.sales.create'], users_count: 1, version: 0 });
    await page.goto(office + '/dashboard#/roles');
    await page.reload();
    await roles.getByText('cashier', { exact: true }).click();
    assert.equal(await roles.getByPlaceholder('مثال: مشرف مبيعات').isDisabled(), true);
    assert.equal(await roles.getByRole('button', { name: 'حذف الدور', exact: true }).count(), 0);

    await page.goto(office + '/dashboard#/settings');
    const settings = page.locator('[data-admin-mock="settings"]');
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').waitFor();
    const originalCompany = state.settings.data.company.company_name;
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').fill('حفظ من لقطة قديمة');
    state.settings.data.company.company_name = 'إعداد عدله مدير آخر';
    await settings.getByRole('button', { name: /حفظ/ }).click();
    await settings.getByText('تعارض في البيانات (409)', { exact: true }).waitFor();
    assert.equal(state.requests.findLast(request => request.method === 'PUT').body.expected.company_name, originalCompany);
    assert.equal(state.settings.data.company.company_name, 'إعداد عدله مدير آخر');
    await settings.getByRole('button', { name: 'إعادة التحميل', exact: true }).click();
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').waitFor();
    assert.equal(await settings.getByPlaceholder('الاسم التجاري للمنشأة').inputValue(), 'إعداد عدله مدير آخر');

    state.me.data.roles = [{ id: 88, name: 'Branch manager' }];
    state.me.data.permissions = ['users.view', 'roles.view', 'roles.manage', 'settings.view', 'settings.manage'];
    state.me.data.branch_id = 1;
    await page.goto(office + '/dashboard#/roles');
    await page.reload();
    assert.equal(await roles.getByPlaceholder('مثال: مشرف مبيعات').isDisabled(), true);
    await page.goto(office + '/dashboard#/settings');
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').waitFor();
    assert.equal(await settings.getByPlaceholder('الاسم التجاري للمنشأة').isDisabled(), true);
    assert.deepEqual(errors, []);
    console.log('PASS: stale saves, current-password confirmation, built-in roles and central controls');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
