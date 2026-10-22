const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const office = process.env.E2E_BACKOFFICE_URL || 'http://127.0.0.1:5173';
const output = path.resolve('test-results/vortix-admin');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  let permissions = ['*'];
  let role = 'super_admin';
  const checkRtlShell = async () => {
    const sidebar = await page.locator('aside').boundingBox();
    const main = await page.locator('main').boundingBox();
    const brand = await page.locator('#brand-home-btn').boundingBox();
    const profile = await page.locator('#profile-pill-btn').boundingBox();
    assert.ok(sidebar.x >= main.x + main.width, 'Sidebar must be on the right of the content');
    assert.ok(brand.x > profile.x, 'Header must place the brand on the right and account on the left');
    assert.equal(await page.locator('header').getAttribute('dir'), 'rtl');
  };
  page.on('pageerror', error => errors.push(error.message));
  await context.addInitScript(() => localStorage.setItem('backoffice_token', 'vortix-interface-test'));
  await context.route('**/api/v1/admin/me', route => route.fulfill({ json: { data: {
    id: 1, name: 'Interface test', email: 'interface@example.test', is_active: true,
    roles: [{ id: 1, name: role }], permissions, branch_id: null, branch: null,
  } } }));
  const furnitureTree = { data: [
    { id: 1, parent_id: null, name_ar: 'غرف المعيشة', name_en: 'Living Room', slug: 'living-room',
      description_ar: null, description_en: null, sort_order: 0, is_active: true,
      image_url: null, products_count: 0, created_at: '2026-10-01T00:00:00Z',
      children: [{ id: 11, parent_id: 1, name_ar: 'كنب زاوية', name_en: 'Corner Sofas',
        slug: 'corner-sofas', description_ar: null, description_en: null, sort_order: 0,
        is_active: true, image_url: null, products_count: 17, created_at: '2026-10-01T00:00:00Z', children: [] }] },
  ] };
  let treeStatus = 200;
  let treeBody = furnitureTree;
  await page.route('**/api/v1/admin/categories**', (route) => {
    const url = route.request().url();
    if (route.request().method() === 'GET' && url.includes('tree=')) {
      return route.fulfill({ status: treeStatus, json: treeBody });
    }
    return route.fulfill({ status: 200, json: { data: [] } });
  });
  try {
    await page.goto(office + '/dashboard');
    await page.locator('[data-side="categories"]').waitFor();
    await checkRtlShell();
    await page.locator('button[data-side="categories"]').click();
    await page.waitForURL(url => url.hash === '#/categories');
    await page.locator('#view-categories [data-categories-mock] h1').waitFor();
    assert.equal(await page.locator('#brand-home-btn').isVisible(), true, 'Categories must retain Vortix navigation');
    assert.equal(await page.locator('#view-overview').isVisible(), false);
    assert.equal(await page.locator('[data-categories-mock] .categories-surface').evaluate(el => getComputedStyle(el).direction), 'rtl');
    await page.getByText('كنب زاوية', { exact: true }).waitFor();
    await checkRtlShell();
    fs.mkdirSync(output, { recursive: true });
    await page.screenshot({ path: path.join(output, 'categories-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.reload();
    await page.locator('#view-categories h1').waitFor();
    await page.locator('[data-nav="manage"]').click();
    await page.waitForURL(url => url.hash === '#/dashboard');
    assert.equal(await page.locator('#view-manage').isVisible(), true, 'Leaving categories must preserve the selected Vortix view');
    await page.goBack();
    await page.locator('#view-categories h1').waitFor();
    await page.getByText('كنب زاوية', { exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await checkRtlShell();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'Mobile must not scroll horizontally');
    await page.screenshot({ path: path.join(output, 'categories-mobile.png'), fullPage: true, animations: 'disabled' });
    await page.goto(office + '/products');
    await page.getByRole('heading', { name: 'المنتجات', exact: true }).waitFor();
    await page.locator('#brand-home-btn').waitFor();
    assert.equal(await page.locator('.side-logo').count(), 0, 'Legacy shell must not return');
    treeStatus = 500;
    treeBody = { message: 'fail' };
    await page.goto(office + '/dashboard#/categories');
    await page.getByText('إعادة المحاولة', { exact: true }).waitFor();
    treeStatus = 200;
    treeBody = furnitureTree;
    await page.getByRole('button', { name: 'إعادة المحاولة' }).click();
    await page.getByText('كنب زاوية', { exact: true }).waitFor();
    treeBody = { data: [] };
    await page.reload();
    await page.getByText('لا توجد أقسام بعد', { exact: true }).waitFor();
    treeBody = furnitureTree;
    permissions = ['categories.view'];
    role = 'staff';
    await page.goto(office + '/categories');
    await page.locator('#view-categories [data-categories-mock] h1').waitFor();
    await page.getByText('كنب زاوية', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'قسم رئيسي' }).count(), 0, 'Viewer must not see create button');
    permissions = ['dashboard.view'];
    role = 'staff';
    await page.goto(office + '/dashboard');
    await page.locator('#brand-home-btn').waitFor();
    assert.equal(await page.locator('[data-side="categories"]').count(), 0, 'Hide categories without view permission');
    await page.goto(office + '/categories');
    await page.getByText('ليس لديك صلاحية لعرض هذه الصفحة', { exact: true }).waitFor();
    permissions = ['*'];
    role = 'cashier';
    await page.goto(office + '/categories');
    await page.waitForURL(office + '/dashboard');
    await page.locator('#brand-home-btn').waitFor();
    assert.equal(await page.locator('[data-side="categories"]').count(), 0, 'Cashier must not enter categories');
    assert.deepEqual(errors, [], 'Browser runtime errors');
    console.log('PASS: RTL header/sidebar on desktop and mobile; categories navigation, history, legacy redirects and role restrictions.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
