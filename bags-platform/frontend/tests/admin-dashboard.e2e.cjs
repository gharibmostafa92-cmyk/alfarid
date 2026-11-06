const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173';
const amount = new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'EGP', maximumFractionDigits: 2, minimumFractionDigits: 0 });
const dashboard = {
  today: { sales_total: 2468, sales_by_channel: { store: 1100, wholesale: 900, pos: 468 }, new_orders_count: 7 },
  month: { sales_total: 18240, sales_by_channel: { store: 9100, wholesale: 5600, pos: 3540 } },
  low_stock_count: 4,
  pending_wholesale_applications: 2,
  top_products: [{ product_variant_id: 8, product_name: 'حقيبة جلد', sku: 'BAG-8', qty_sold: 10, revenue: 8200 }],
  top_branches: [],
};

async function verifyDashboard(browser, mobile) {
  const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 } });
  try {
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    let requests = 0;
    let canViewReports = true;
    let fail = !mobile;
    const user = () => ({
      id: 1, name: 'موظف الاختبار', email: 'staff@example.test', roles: ['manager'],
      permissions: canViewReports ? ['reports.view', 'orders.view', 'inventory.view'] : ['orders.view'],
    });
    await context.route('**/api/v1/**', async (route) => {
      const request = route.request();
      const pathname = new URL(request.url()).pathname;
      const headers = {
        'access-control-allow-origin': '*',
        'access-control-allow-headers': '*',
        'access-control-allow-methods': '*',
      };
      const json = (payload, status = 200) => route.fulfill({ headers, status, json: payload });
      if (request.method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (pathname.endsWith('/auth/handoff/exchange')) {
        return json({
          token: 'dashboard-test-session',
          data: user(),
          redirect: { path: canViewReports ? '/dashboard' : '/#/reports' },
        });
      }
      if (pathname.endsWith('/admin/me')) return json({ data: user() });
      if (pathname.endsWith('/admin/dashboard')) {
        requests++;
        if (fail) return json({ message: 'Unavailable' }, 500);
        return json({ data: dashboard });
      }
      if (pathname.endsWith('/admin/reports/sales')) {
        const date = new URL(request.url()).searchParams.get('to');
        return json({ data: { rows: [{ period: date, net_sales: 920, discounts: 85 }] } });
      }
      return json({ data: [] });
    });

    await page.goto(base + '/auth/callback#code=dashboard-test');
    const overview = page.locator('.admin-overview');
    await overview.getByRole('heading', { name: 'مرحبًا بك، موظف الاختبار' }).waitFor();
    if (!mobile) {
      await overview.getByRole('alert').waitFor();
      assert.equal(await overview.locator('.admin-sales-total').count(), 0);
      fail = false;
      await overview.getByRole('button', { name: 'إعادة المحاولة' }).click();
    }
    const salesTotal = overview.locator('.admin-sales-total');
    await salesTotal.getByText(amount.format(dashboard.today.sales_total), { exact: true }).waitFor();
    assert.equal(await overview.locator('.admin-vortix-grid').first().evaluate(el => getComputedStyle(el).direction), 'rtl');
    assert.equal(await overview.locator('table').evaluate(el => getComputedStyle(el).direction), 'rtl');
    const comparison = overview.getByRole('region', { name: 'مقارنة المبيعات' });
    const chartBars = comparison.getByRole('img');
    await chartBars.last().waitFor();
    assert.equal(await chartBars.count(), 8);
    assert.ok((await chartBars.last().getAttribute('aria-label')).includes(`المبيعات ${amount.format(920)} · الخصومات ${amount.format(85)}`));
    await overview.getByRole('button', { name: 'هذا الشهر' }).click();
    await salesTotal.getByText(amount.format(dashboard.month.sales_total), { exact: true }).waitFor();
    await overview.getByText('حقيبة جلد', { exact: true }).waitFor();
    await overview.getByRole('button', { name: 'عرض الفروع', exact: true }).click();
    await overview.getByText('لا توجد مبيعات فروع مسجّلة هذا الشهر.').waitFor();
    await overview.getByRole('button', { name: 'عرض المنتجات', exact: true }).click();
    assert.ok(await page.getByText('موظف الاختبار', { exact: true }).isVisible());
    assert.equal(await page.locator('#view-messages, [data-side="messages"], #top-help-btn').count(), 0);
    assert.equal(await page.locator('#btn-open-transfer').count(), 0);
    assert.equal(await overview.evaluate((element) => element.scrollWidth > element.clientWidth), false);
    if (mobile) {
      await page.getByRole('button', { name: 'المزيد' }).click();
      assert.equal(await page.getByRole('button', { name: 'الرسائل', exact: true }).count(), 0);
      await page.getByRole('button', { name: 'الوضع الداكن' }).click();
      await page.keyboard.press('Escape');
      await page.locator('[data-vortix-host][data-theme="dark"]').waitFor();
      await page.locator('#theme-wipe-overlay').waitFor({ state: 'hidden' });
    }
    if (process.env.ADMIN_DASHBOARD_SCREENSHOTS) {
      await page.screenshot({ path: path.join(process.env.ADMIN_DASHBOARD_SCREENSHOTS, mobile ? 'dashboard-mobile.png' : 'dashboard-desktop.png'), fullPage: true });
    }

    canViewReports = false;
    const previousRequests = requests;
    await page.goto(base + '/auth/callback#code=dashboard-limited-staff');
    await page.waitForURL('**/#/reports');
    await page.getByText('موظف الاختبار', { exact: true }).waitFor();
    await page.locator('#brand-home-btn').click();
    await overview.getByText('يتطلب عرض المؤشرات صلاحية التقارير.').waitFor();
    assert.equal(requests, previousRequests);
    assert.deepEqual(pageErrors, []);
  } finally {
    await context.close();
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const mobile of [false, true]) await verifyDashboard(browser, mobile);
    console.log('Admin dashboard passed on desktop and mobile.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
