const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:5173';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBZkAAAAASUVORK5CYII=', 'base64');
const resource = (id, parent_id, name_ar, name_en, sort_order, is_active = true, products_count = 0) => ({
  id, parent_id, name_ar, name_en, slug: name_en.toLowerCase().replaceAll(' ', '-'), sort_order,
  is_active, products_count, description_ar: null, description_en: null, image_url: null, created_at: '2026-10-01T00:00:00Z',
});

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  let permissions = ['*'];
  let reads = 0;
  let failure = null;
  let delayWrite = 0;
  const writes = [];
  const records = new Map([
    resource(10, null, 'حقائب', 'Bags', 10), resource(11, 10, 'إضاءة صغيرة', 'Small Light', 40),
    resource(20, null, 'محافظ', 'Wallets', 30), resource(30, null, 'قسم مخفي', 'Hidden', 50, false),
    resource(31, 30, 'قسم فرعي مخفي', 'Hidden Child', 100, false), resource(40, null, 'منتجات منشورة', 'Published', 60, true, 2),
  ].map(item => [item.id, item]));
  let nextId = 50;
  const tree = (parent = null) => [...records.values()].filter(item => item.parent_id === parent)
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    .map(item => ({ ...item, children: tree(item.id) }));
  await context.addInitScript(() => {
    localStorage.setItem('backoffice_token', 'categories-operations-test');
    const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
    window.categoryObjectUrls = [];
    URL.createObjectURL = blob => { const url = create(blob); window.categoryObjectUrls.push({ url, revoked: false }); return url; };
    URL.revokeObjectURL = url => { const item = window.categoryObjectUrls.find(item => item.url === url); if (item) item.revoked = true; revoke(url); };
  });
  await context.route('**/api/v1/admin/me', route => route.fulfill({ json: { data: {
    id: 1, name: 'Operations test', email: 'operations@example.test', is_active: true,
    roles: [{ id: 1, name: 'staff' }], permissions, branch_id: null, branch: null,
  } } }));
  await page.route('**/mock-category.png', route => route.fulfill({ contentType: 'image/png', body: png }));
  await page.route('**/api/v1/admin/categories**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET') {
      reads++;
      return route.fulfill({ json: { data: tree() } });
    }
    let payload;
    if (request.headers()['content-type']?.startsWith('multipart/')) {
      const form = await new Response(request.postDataBuffer(), { headers: { 'Content-Type': request.headers()['content-type'] } }).formData();
      payload = Object.fromEntries(form.entries());
    } else payload = request.postDataJSON();
    writes.push({ method: request.method(), path: url.pathname, payload });
    const wait = delayWrite;
    delayWrite = 0;
    if (wait) await new Promise(resolve => setTimeout(resolve, wait));
    if (failure) {
      const result = failure;
      failure = null;
      return route.fulfill({ status: result.status, json: { message: result.message, errors: result.errors } });
    }
    if (url.pathname.endsWith('/reorder')) {
      for (const item of payload.items) Object.assign(records.get(item.id), { parent_id: item.parent_id, sort_order: item.sort_order });
      return route.fulfill({ json: { message: 'تم حفظ الترتيب' } });
    }
    const id = Number(url.pathname.split('/').at(-1));
    if (request.method() === 'DELETE') {
      records.delete(id);
      return route.fulfill({ json: { message: 'تم الحذف' } });
    }
    const existing = records.get(id);
    const item = existing || resource(nextId++, null, '', '', 0);
    for (const key of ['name_ar', 'name_en', 'slug', 'description_ar', 'description_en']) {
      if (key in payload) item[key] = payload[key] || null;
    }
    if ('parent_id' in payload) item.parent_id = payload.parent_id === '' ? null : Number(payload.parent_id);
    if ('sort_order' in payload) item.sort_order = Number(payload.sort_order);
    if ('is_active' in payload) item.is_active = payload.is_active === '1';
    if (payload.remove_image === '1') item.image_url = null;
    if (payload.image) item.image_url = 'http://localhost:8000/mock-category.png';
    records.set(item.id, item);
    return route.fulfill({ status: existing ? 200 : 201, json: { data: item } });
  });
  const row = id => page.locator(`[data-category-id="${id}"]`);
  const dialog = () => page.getByRole('dialog');
  const field = name => dialog().getByRole('group', { name: new RegExp('^' + name) }).getByRole('textbox');
  const parentField = () => dialog().getByRole('group', { name: /^القسم الأب/ });
  const edit = async id => { await row(id).hover(); await row(id).getByTitle('تعديل', { exact: true }).click(); };
  const deleteDialog = async id => { await row(id).hover(); await row(id).getByTitle('حذف', { exact: true }).click(); };
  const waitClosed = () => dialog().waitFor({ state: 'hidden' });
  const chooseParent = async name => {
    await parentField().getByRole('button').first().click();
    await parentField().getByRole('button', { name, exact: true }).click();
  };
  try {
    await page.goto(office + '/dashboard#/categories');
    await row(11).waitFor();
    await page.getByPlaceholder('ابحث في الأقسام…').fill('اضاءه');
    assert.equal(await row(11).isVisible(), true, 'Arabic search must ignore hamza and ta marbuta differences');
    await page.getByPlaceholder('ابحث في الأقسام…').fill('');

    // Create, bilingual descriptions, multipart image and Arabic numeric order.
    await page.getByRole('button', { name: 'قسم رئيسي', exact: true }).click();
    await field('الاسم بالعربي').fill('قسم جديد');
    await field('الاسم بالإنجليزي').fill('New Category');
    await chooseParent('محافظ');
    await field('الترتيب').fill('٣');
    await field('الوصف').fill('وصف عربي');
    await dialog().getByRole('button', { name: 'EN', exact: true }).click();
    await field('الوصف').fill('English description');
    await dialog().locator('input[type="file"]').setInputFiles({ name: 'category.png', mimeType: 'image/png', buffer: png });
    await dialog().getByRole('button', { name: 'إنشاء القسم', exact: true }).click();
    await waitClosed();
    await row(50).waitFor();
    assert.equal(records.get(50).description_ar, 'وصف عربي');
    assert.equal(records.get(50).description_en, 'English description');
    assert.equal(records.get(50).parent_id, 20);
    assert.equal(records.get(50).sort_order, 3);
    assert.equal(writes.at(-1).payload.is_active, '1');
    assert.ok(writes.at(-1).payload.image instanceof Blob);
    assert.equal(await page.evaluate(() => window.categoryObjectUrls.every(item => item.revoked)), true, 'Preview URL must be released on close');

    await edit(50);
    await field('الاسم بالإنجليزي').fill('Changed Name');
    assert.equal(await field('الرابط').inputValue(), 'new-category', 'Changing the name must preserve the existing slug');
    await chooseParent('حقائب');
    await dialog().getByRole('button', { name: 'إزالة', exact: true }).click();
    await dialog().getByRole('button', { name: 'حفظ', exact: true }).click();
    await waitClosed();
    assert.equal(writes.at(-1).payload._method, 'PUT');
    assert.equal(records.get(50).parent_id, 10);
    assert.equal(records.get(50).image_url, null);

    // Server validation stays in the form; an invalid image never reaches the API.
    await edit(50);
    await dialog().locator('input[type="file"]').setInputFiles({ name: 'bad.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') });
    await dialog().getByText('صيغة غير مدعومة', { exact: true }).waitFor();
    failure = { status: 422, message: 'راجع البيانات', errors: { name_ar: ['الاسم مستخدم'] } };
    await dialog().getByRole('button', { name: 'حفظ', exact: true }).click();
    await dialog().getByText('الاسم مستخدم', { exact: true }).waitFor();
    await page.keyboard.press('Escape');
    await waitClosed();

    // Form fields and Escape are locked until an in-flight update completes.
    await edit(50);
    delayWrite = 500;
    await dialog().getByRole('button', { name: 'حفظ', exact: true }).click();
    assert.equal(await field('الاسم بالعربي').isDisabled(), true);
    await page.keyboard.press('Escape');
    assert.equal(await dialog().isVisible(), true);
    await waitClosed();

    // Cancelling an unsaved replacement releases its local preview as well.
    await edit(50);
    await dialog().locator('input[type="file"]').setInputFiles({ name: 'cancelled.png', mimeType: 'image/png', buffer: png });
    await dialog().getByRole('button', { name: 'إزالة', exact: true }).waitFor();
    await page.keyboard.press('Escape');
    await waitClosed();
    assert.equal(await page.evaluate(() => window.categoryObjectUrls.every(item => item.revoked)), true);

    const deleteCount = writes.filter(item => item.method === 'DELETE').length;
    await deleteDialog(40);
    await dialog().getByText('لا يمكن حذف هذا القسم', { exact: true }).waitFor();
    assert.equal(await dialog().getByRole('button', { name: 'حذف القسم', exact: true }).count(), 0);
    assert.equal(writes.filter(item => item.method === 'DELETE').length, deleteCount);
    await dialog().getByRole('button', { name: 'إغلاق', exact: true }).click();

    await row(40).click();
    await page.getByRole('button', { name: 'تعطيل', exact: true }).click();
    failure = { status: 422, message: 'يوجد منتجات منشورة تحت هذا القسم' };
    await dialog().getByRole('button', { name: 'تعطيل', exact: true }).click();
    await dialog().getByText('يوجد منتجات منشورة تحت هذا القسم', { exact: true }).waitFor();
    assert.equal(records.get(40).is_active, true, 'Rejected deactivation must preserve the active status');
    await dialog().getByRole('button', { name: 'إغلاق', exact: true }).click();

    await deleteDialog(50);
    failure = { status: 409, message: 'البيانات اتغيرت، حدّث الصفحة' };
    delayWrite = 500;
    await dialog().getByRole('button', { name: 'حذف القسم', exact: true }).click();
    await page.keyboard.press('Escape');
    assert.equal(await dialog().isVisible(), true, 'Confirmation must stay open during its request');
    await dialog().getByText('البيانات اتغيرت، حدّث الصفحة', { exact: true }).waitFor();
    await dialog().getByRole('button', { name: 'إغلاق', exact: true }).click();
    await deleteDialog(50);
    await dialog().getByRole('button', { name: 'حذف القسم', exact: true }).click();
    await waitClosed();
    await row(50).waitFor({ state: 'hidden' });

    // Activating a child of an inactive parent shows the store visibility warning.
    await row(10).getByRole('button').first().click();
    await row(11).waitFor({ state: 'hidden' });
    await row(31).click();
    await page.getByRole('button', { name: 'تفعيل', exact: true }).click();
    await dialog().getByText('القسم مش هيظهر في المتجر طول ما القسم الأب معطّل', { exact: true }).waitFor();
    await dialog().getByRole('button', { name: 'تفعيل', exact: true }).click();
    await waitClosed();
    assert.equal(records.get(31).is_active, true);
    assert.equal(await row(11).isVisible(), false, 'Reload must preserve collapsed branches');
    await row(10).getByRole('button').first().click();

    // Native drag must keep its source DOM node mounted throughout the drag.
    await row(11).click();
    await row(11).dragTo(row(20));
    await page.getByRole('button', { name: 'حفظ الترتيب', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'قسم رئيسي', exact: true }).isDisabled(), true);
    failure = { status: 422, message: 'أقصى عمق للأقسام 3 مستويات' };
    await page.getByRole('button', { name: 'حفظ الترتيب', exact: true }).click();
    await page.getByText('أقصى عمق للأقسام 3 مستويات', { exact: true }).waitFor();
    assert.equal(records.get(11).parent_id, 10);
    await page.getByRole('button', { name: 'إلغاء التغييرات', exact: true }).click();
    await row(11).dragTo(row(30));
    await page.getByRole('button', { name: 'حفظ الترتيب', exact: true }).click();
    await page.getByRole('button', { name: 'حفظ الترتيب', exact: true }).waitFor({ state: 'hidden' });
    assert.equal(records.get(11).parent_id, 30);
    assert.ok(records.get(31).sort_order < records.get(11).sort_order, 'Sparse destination sort values must not put the moved child first');

    const beforeDenied = reads;
    permissions = [];
    await page.goto(office + '/categories');
    await page.getByText('ليس لديك صلاحية لعرض هذه الصفحة', { exact: true }).waitFor();
    assert.equal(reads, beforeDenied, 'Forbidden screen must not request the tree');
    assert.deepEqual(errors, []);
    console.log('PASS: categories create/edit/images, bilingual data, validation, delete, activation, native drag/reorder, busy dialogs and permissions.');
  } catch (error) {
    console.error('Page URL:', page.url());
    console.error('Recent writes:', writes.slice(-2).map(item => ({ ...item, payload: { ...item.payload, image: undefined } })));
    throw error;
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
