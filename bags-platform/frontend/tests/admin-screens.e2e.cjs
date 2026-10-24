const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:5173';

(async () => {
  console.log('Testing Users, Roles, and Settings screens on', office);
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();

  const errors = [];
  page.on('pageerror', err => errors.push(err.message));

  await context.addInitScript(() => {
    localStorage.setItem('backoffice_token', 'test-token');
  });

  const state = await require('./admin-api-routes.cjs')(context);

  try {
    // 1. Visit dashboard
    await page.goto(office + '/dashboard');
    await page.locator('#brand-home-btn').waitFor();

    // Check top nav has users, roles, settings
    const topNavUsers = page.locator('button[data-nav="users"]');
    const topNavRoles = page.locator('button[data-nav="roles"]');
    const topNavSettings = page.locator('button[data-nav="settings"]');

    assert.equal(await topNavUsers.isVisible(), true, 'Top nav must show users');
    assert.equal(await topNavRoles.isVisible(), true, 'Top nav must show roles');
    assert.equal(await topNavSettings.isVisible(), true, 'Top nav must show settings');

    // 2. Navigate to Users
    console.log('Testing Users screen...');
    await topNavUsers.click();
    await page.waitForURL(url => url.hash.includes('/users'));

    // Wait for shadow root host of AdminMockPage
    const usersHost = page.locator('[data-admin-mock="users"]');
    await usersHost.waitFor();

    // Inside shadow root or surface, check Users screen header and content
    const usersHeading = page.locator('[data-admin-mock="users"]').getByRole('heading', { name: 'المستخدمون' });
    await usersHeading.waitFor();

    // Verify team members table rendered
    const teamHeading = page.locator('[data-admin-mock="users"]').getByRole('heading', { name: 'فريق العمل' });
    await teamHeading.waitFor();

    const ahmedUser = page.locator('[data-admin-mock="users"]').getByText('مدير النظام').first();
    await ahmedUser.waitFor();
    const selfRow = page.locator('[data-admin-mock="users"] tr').filter({hasText:'admin@alfarid.local'});
    await selfRow.getByTitle('الإجراءات').click();
    assert.equal(await selfRow.getByRole('button',{name:'تعطيل الحساب',exact:true}).count(),0);
    await selfRow.getByTitle('الإجراءات').click();

    // 3. Test linking: Click "الأدوار والصلاحيات" tab in the subnav
    console.log('Testing navigation to Roles...');
    const rolesTab = page.locator('[data-admin-mock="users"]').getByRole('tab', { name: /الأدوار والصلاحيات/ });
    await rolesTab.click();
    await page.waitForURL(url => url.hash.includes('/roles'));

    const rolesHost = page.locator('[data-admin-mock="roles"]');
    await rolesHost.waitFor();

    const rolesHeading = page.locator('[data-admin-mock="roles"]').getByRole('heading', { name: 'الأدوار والصلاحيات' });
    await rolesHeading.waitFor();

    // Verify Super Admin and مشرف الاختبارات roles exist
    const superAdminRole = page.locator('[data-admin-mock="roles"]').getByText('Super Admin').first();
    await superAdminRole.waitFor();

    const generalManagerRole = page.locator('[data-admin-mock="roles"]').getByText('مشرف الاختبارات').first();
    await generalManagerRole.waitFor();

    // 4. Test linking from Roles to Users:
    console.log('Testing linking from Role to Users...');
    const viewUsersLink = page.locator('[data-admin-mock="roles"]').getByText(/عرض المستخدمين/);
    await viewUsersLink.waitFor({ timeout: 4000 }).catch(() => {});
    if (await viewUsersLink.isVisible()) {
      await viewUsersLink.click();
      await page.waitForURL(url => url.hash.includes('/users?role='));
      console.log('Successfully navigated from Roles to filtered Users!');
    }

    // 5. Navigate to Settings screen
    console.log('Testing Settings screen...');
    await page.goto(office + '/dashboard#/settings', {waitUntil:'domcontentloaded'});
    const settingsHost = page.locator('[data-admin-mock="settings"]');
    await settingsHost.waitFor();

    const settingsHeading = page.locator('[data-admin-mock="settings"]').getByRole('heading', { name: 'الإعدادات العامة' });
    await settingsHeading.waitFor();

    // Check settings tabs
    const companyTab = page.locator('[data-admin-mock="settings"]').getByText('بيانات الشركة').first();
    const taxTab = page.locator('[data-admin-mock="settings"]').getByText('الضرائب والفواتير').first();
    const numberingTab = page.locator('[data-admin-mock="settings"]').getByText('ترقيم المستندات').first();
    const ordersTab = page.locator('[data-admin-mock="settings"]').getByText('سياسة الإرجاع').first();
    const purchasesTab = page.locator('[data-admin-mock="settings"]').getByText('دورة المشتريات').first();

    await companyTab.waitFor();
    await taxTab.waitFor();
    await numberingTab.waitFor();
    await ordersTab.waitFor();
    await purchasesTab.waitFor();

    // Test clicking Tax tab
    await taxTab.click();
    await page.locator('[data-admin-mock="settings"]').getByText('ضريبة القيمة المضافة').first().waitFor();
    await page.locator('[data-admin-mock="settings"]').getByText('محاكاة حية').first().waitFor();

    // Test clicking Numbering tab
    await numberingTab.click();
    await page.locator('[data-admin-mock="settings"]').getByText('فواتير البيع').first().waitFor();

    // Test clicking Purchases tab
    await purchasesTab.click();
    await page.locator('[data-admin-mock="settings"]').getByText('دورة المشتريات').first().waitFor();


    await page.goto(office + '/dashboard#/settings', {waitUntil:'domcontentloaded'});
    const settings = page.locator('[data-admin-mock="settings"]');
    await settings.getByRole('button', {name:/بيانات الشركة/}).click();
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').fill('شركة معدلة');
    const saved = page.waitForResponse(r => r.request().method() === 'PUT');
    await settings.getByRole('button', {name:/حفظ/}).click();
    await saved;
    assert.deepEqual(state.lastSettingsPatch, {company_name:'شركة معدلة'});
    await page.reload({waitUntil:'domcontentloaded'});
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').waitFor();
    assert.equal(await settings.getByPlaceholder('الاسم التجاري للمنشأة').inputValue(), 'شركة معدلة');
    await settings.getByRole('button',{name:/ترقيم المستندات/}).click();
    const invoice = settings.locator('.v-doc').filter({hasText:'فواتير البيع'});
    await invoice.locator('input[type="number"]').first().fill('21');
    await settings.getByRole('button',{name:/حفظ/}).click();
    await settings.getByText('يجب ألا يتجاوز حشو الأصفار 20',{exact:true}).waitFor();
    await settings.getByRole('button',{name:/تراجع|إلغاء التغييرات|إعادة/}).last().click();

    await page.goto(office + '/dashboard#/users', {waitUntil:'domcontentloaded'});
    const users = page.locator('[data-admin-mock="users"]');
    state.rejectEmail = true;
    await users.getByRole('button',{name:'مستخدم جديد',exact:true}).click();
    await users.getByPlaceholder('مثال: محمد أحمد علي').fill('مستخدم جديد');
    await users.getByPlaceholder('name@company.com').fill('duplicate@example.test');
    await users.getByPlaceholder('أدخل كلمة مرور قوية').fill('Long-password-123');
    await users.getByRole('button',{name:'إنشاء المستخدم',exact:true}).click();
    await users.getByText('هذا البريد مستخدم بالفعل في النظام',{exact:true}).waitFor();
    state.rejectEmail = false;
    await users.getByPlaceholder('name@company.com').fill('created@example.test');
    await users.getByPlaceholder('اختر دوراً أو أكثر').click();
    await users.getByRole('button',{name:/مشرف الاختبارات.*صلاحية/}).click();
    const branchField = users.locator('.v-drawer label').filter({hasText:/^الفرع/});
    await branchField.locator('button').first().click();
    await branchField.getByText('فرع الاختبارات',{exact:true}).click();
    await users.getByRole('button',{name:'إنشاء المستخدم',exact:true}).click();
    const createdRow = users.locator('tr').filter({hasText:'created@example.test'});
    await createdRow.waitFor();
    assert.deepEqual(state.requests.findLast(r => r.resource === 'users' && r.method === 'POST').body.roles,['مشرف الاختبارات']);
    await createdRow.getByTitle('تعديل',{exact:true}).click();
    // Another administrator assigns a role after this drawer loaded. A name edit must preserve it.
    state.users.data.find(user => user.id === 99).roles.push({id:987,name:'دور أضيف بالتزامن'});
    await users.getByPlaceholder('مثال: محمد أحمد علي').fill('مستخدم معدل');
    await users.getByRole('button',{name:'حفظ التعديلات',exact:true}).click();
    await createdRow.getByText('مستخدم معدل',{exact:true}).waitFor();
    assert.equal('password' in state.requests.findLast(r => r.resource === 'users/99' && r.method === 'PATCH').body,false);
    assert.equal('roles' in state.requests.findLast(r => r.resource === 'users/99' && r.method === 'PATCH').body,false);
    assert.equal('is_active' in state.requests.findLast(r => r.resource === 'users/99' && r.method === 'PATCH').body,false);
    assert.equal(state.users.data.find(user => user.id === 99).roles.some(role => role.id === 987),true);
    await createdRow.getByTitle('الإجراءات').click();
    await createdRow.getByRole('button',{name:'تعطيل الحساب',exact:true}).click();
    await users.getByRole('button',{name:'تعطيل الحساب',exact:true}).click();
    await createdRow.getByText('معطل',{exact:true}).waitFor();
    await createdRow.getByTitle('الإجراءات').click();
    await createdRow.getByRole('button',{name:'إعادة تفعيل الحساب',exact:true}).click();
    await createdRow.getByText('نشط',{exact:true}).waitFor();
    const search = users.getByPlaceholder('بحث بالاسم أو البريد');
    const searched = page.waitForRequest(r => new URL(r.url()).searchParams.get('search') === 'موظف');
    await search.fill('موظف');
    await searched;
    await users.getByRole('button',{name:'كل الفروع',exact:true}).click();
    const noBranch = page.waitForRequest(r => r.url().includes('/users?') && new URL(r.url()).searchParams.get('branch_id') === '');
    await users.getByRole('button',{name:'بدون فرع',exact:true}).click();
    await noBranch;
    const inactiveRequest = page.waitForRequest(r => r.url().includes('/users?') && new URL(r.url()).searchParams.get('is_active') === '0');
    await users.getByRole('button',{name:'معطل',exact:true}).click();
    await inactiveRequest;
    state.roles.data.find(r => r.name === 'مشرف الاختبارات').users_count = 0;
    state.rejectDelete = true;
    await page.goto(office + '/dashboard#/roles', {waitUntil:'domcontentloaded'});
    const roles = page.locator('[data-admin-mock="roles"]');
    await roles.getByText('مشرف الاختبارات',{exact:true}).first().click();
    await roles.getByRole('button',{name:'حذف الدور',exact:true}).click();
    await roles.getByRole('button',{name:'حذف نهائي',exact:true}).click();
    await roles.getByText('الدور مستخدم ولا يمكن حذفه',{exact:true}).waitFor();
    await roles.getByRole('button',{name:'حسناً',exact:true}).click();
    state.rejectDelete = false;
    await roles.getByRole('button',{name:'دور جديد',exact:true}).click();
    await roles.getByPlaceholder('مثال: مشرف مبيعات').fill('مشرف جديد');
    await roles.getByRole('button',{name:'حفظ التغييرات',exact:true}).click();
    await roles.getByText('مشرف جديد',{exact:true}).first().waitFor();
    assert.equal(await roles.getByPlaceholder('مثال: مشرف مبيعات').inputValue(),'مشرف جديد');
    await roles.getByPlaceholder('مثال: مشرف مبيعات').fill('مشرف بعد التعديل');
    await roles.getByRole('button',{name:'حفظ التغييرات',exact:true}).click();
    await roles.getByText('مشرف بعد التعديل',{exact:true}).first().waitFor();
    assert.deepEqual(state.requests.findLast(r => r.resource === 'roles/99' && r.method === 'PATCH').body,{expected_version:0,name:'مشرف بعد التعديل'});
    await page.reload({waitUntil:'domcontentloaded'});
    await roles.getByText('مشرف بعد التعديل',{exact:true}).first().click();
    await roles.getByRole('button',{name:'حذف الدور',exact:true}).click();
    await roles.getByRole('button',{name:'حذف نهائي',exact:true}).click();
    await roles.getByText('تم حذف الدور',{exact:true}).waitFor();

    state.me.data.roles = [{id:40,name:'مراجع'}];
    state.me.data.permissions = ['users.view','users.update','roles.view'];
    state.restricted = true;
    await page.goto(office + '/dashboard#/users', {waitUntil:'domcontentloaded'});
    await page.reload({waitUntil:'domcontentloaded'});
    await users.getByText('فرع الاختبارات',{exact:true}).first().waitFor();
    assert.equal(await users.getByTitle('لا يمكن إدارة حساب Super Admin').isDisabled(),true);
    assert.equal(await page.locator('button[data-nav="settings"]').count(),0);
    await page.goto(office + '/dashboard#/roles', {waitUntil:'domcontentloaded'});
    await roles.getByText(/صلاحيات الدور الحالي للقراءة فقط/).waitFor();
    assert.equal(await roles.getByPlaceholder('مثال: مشرف مبيعات').isDisabled(),true);
    await page.goto(office + '/dashboard#/settings', {waitUntil:'domcontentloaded'});
    await settings.getByText('لا تملك صلاحية الوصول لهذه الصفحة',{exact:true}).waitFor();
    state.me.data.permissions.push('settings.view');
    await page.reload({waitUntil:'domcontentloaded'});
    await settings.getByPlaceholder('الاسم التجاري للمنشأة').waitFor();
    assert.equal(await settings.getByPlaceholder('الاسم التجاري للمنشأة').isDisabled(),true);
    assert.equal(state.requests.some(r => r.resource === 'users' && r.params.get('is_active') === '0'),true);
    console.log('All screens loaded and linked successfully without errors!');
    assert.equal(errors.length, 0, 'No console errors: ' + errors.join(', '));
  } finally {
    await browser.close();
  }
})();

