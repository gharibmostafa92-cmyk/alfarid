const assert = require('node:assert/strict');
const { test } = require('node:test');
const { chromium } = require('playwright');

const baseURL = process.env.STOREFRONT_URL || 'http://localhost:3001';
const user = { id: 1, name: 'QA Customer', email: 'customer@example.test', phone: '01012345678' };
const createdAt = '2026-11-19T12:00:00Z';
const orders = Array.from({ length: 16 }, (_, i) => ({
  id: i + 1, order_number: `ORD-${1001 + i}`, channel: 'store',
  status: ['pending', 'confirmed', 'processing', 'shipped', 'delivered'][i % 5],
  grand_total: '1299.99', items: [], shipments: [],
}));
const transactions = Array.from({ length: 21 }, (_, i) => ({
  id: i + 1, type: 'deposit', type_label: i === 20 ? 'رصيد سابق' : 'إضافة رصيد',
  amount: i === 1 ? '-20.00' : '10.00', created_at: createdAt,
  ...(i === 0 ? { reference_type: 'order', reference_id: 1, reference_label: 'طلب #1' } : {}),
}));
const returns = Array.from({ length: 21 }, (_, i) => ({
  id: i + 1, return_number: `RET-${1001 + i}`, created_at: createdAt, items: [],
  status: ['pending', 'approved', 'rejected', 'received', 'refunded', 'cancelled'][i % 6],
}));

async function open(t, browser, { destination, lang = 'ar', empty = false } = {}) {
  const context = await browser.newContext({ baseURL, timezoneId: 'Africa/Cairo' });
  const identity = { type: 'customer', subtype: destination };
  const redirect = { destination, path: destination === 'wholesale' ? '/wholesale' : '/' };
  const session = { data: user, actor: identity, redirect };
  await context.addInitScript(({ language, origin }) => {
    if (location.origin === origin) localStorage.setItem('varya_store_lang', language);
  }, { language: lang, origin: new URL(baseURL).origin });
  await context.route('**/api/v1/**', async route => {
    const request = route.request();
    const { pathname, searchParams } = new URL(request.url());
    const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' };
    const send = (json, status = 200) => route.fulfill({ json, status, headers });
    const pageNumber = Number(searchParams.get('page') || 1);
    const paginate = (rows, size) => ({
      data: rows.slice((pageNumber - 1) * size, pageNumber * size),
      current_page: pageNumber, last_page: Math.max(1, Math.ceil(rows.length / size)), total: rows.length,
    });
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
    if (pathname.endsWith('/auth/me')) return send(session);
    if (pathname.endsWith('/auth/login')) {
      return send({ ...session, redirect: { ...redirect, app: 'storefront', path: '/account' }, token: 'test-account-token' });
    }
    if (pathname.endsWith('/branding')) return send({ data: { name: 'VARYA' } });
    if (pathname.endsWith('/account/orders')) {
      const { data, ...meta } = paginate(orders, 15);
      return send({ data, meta });
    }
    if (pathname.endsWith('/account/orders/1/cancel') && request.method() === 'POST') {
      return send({ data: { ...orders[0], status: 'cancelled' } });
    }
    if (pathname.endsWith('/account/wallet')) {
      return send({ balance: empty ? '0.00' : '180.00', transactions: paginate(empty ? [] : transactions, 20) });
    }
    if (pathname.endsWith('/account/returns')) {
      const { data, ...meta } = paginate(empty ? [] : returns, 20);
      return send({ data, meta });
    }
    if (pathname.endsWith('/track')) {
      if (searchParams.get('phone') !== user.phone) return send({}, 404);
      return send({ data: { order_number: 'ORD-1001', status: 'shipped', items: [],
        status_history: [{ status: 'confirmed', at: createdAt }] } });
    }
    return send({ data: [] });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    await context.close();
    assert.deepEqual(errors, []);
  });
  if (destination) {
    await page.goto('/auth/login?redirect=%2Faccount');
    await page.locator('#login').fill(user.email);
    await page.locator('#password').fill('account-test-password');
    await page.locator('form').filter({ has: page.locator('#login') }).locator('button[type=submit]').click();
    await page.waitForURL(url => url.pathname === '/account');
    await page.locator('main').getByText(user.name, { exact: true }).waitFor();
  }
  return page;
}

test('customer account in the browser', { timeout: 180000 }, async t => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  t.after(() => browser.close());

  await t.test('HT-ACC-01 redirects guests and displays both account types', async t => {
    const guest = await open(t, browser);
    for (const path of ['/account', '/account/orders', '/account/wallet', '/account/returns']) {
      await guest.goto(path);
      await guest.waitForURL(url => url.pathname === '/auth/login' && url.searchParams.get('redirect') === path);
    }
    for (const [destination, type] of [['retail', 'Member'], ['wholesale', 'Wholesale account']]) {
      const page = await open(t, browser, { destination, lang: 'en' });
      await page.locator('main').getByText(user.email, { exact: true }).waitFor();
      await page.locator('main').getByText(type, { exact: true }).waitFor();
      for (const [label, path] of [['My profile', '/account'], ['Orders', '/account/orders'],
        ['Wallet', '/account/wallet'], ['Returns', '/account/returns']]) {
        const link = page.getByRole('navigation', { name: 'Account sections' }).getByRole('link', { name: label, exact: true });
        await link.click();
        await page.waitForURL(url => url.pathname === path);
      }
    }
  });

  await t.test('HT-ACC-02 shows order actions, cancels and loads the next page', async t => {
    const page = await open(t, browser, { destination: 'retail' });
    await page.goto('/account/orders');
    const first = page.locator('details').filter({ has: page.locator('summary').filter({ hasText: 'ORD-1001' }) });
    await first.locator('summary').click();
    await first.getByText('قيد المراجعة', { exact: true }).waitFor();
    assert.match(await first.locator('summary strong').innerText(), /1,299\.99|١٬٢٩٩٫٩٩/);
    assert.equal(await first.getByRole('link', { name: 'تتبع' }).getAttribute('href'), '/orders/ORD-1001/track');
    await first.getByRole('button', { name: 'إلغاء الطلب', exact: true }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'تأكيد الإلغاء' }).click();
    await first.getByText('ملغي', { exact: true }).waitFor();
    for (const id of [3, 4, 5]) {
      const summary = page.locator('summary').filter({ hasText: `ORD-${1000 + id}` });
      await summary.click();
      assert.equal(await summary.locator('..').getByRole('button', { name: 'إلغاء الطلب' }).count(), 0);
    }
    await page.getByRole('button', { name: 'عرض المزيد' }).click();
    await page.locator('summary').filter({ hasText: 'ORD-1016' }).waitFor();
    assert.equal(await page.locator('main summary').count(), 16);
  });

  await t.test('HT-ACC-03 public tracking shows history and clears it after a mismatch', async t => {
    const page = await open(t, browser);
    await page.goto('/orders/ORD-1001/track');
    const phone = page.getByLabel('رقم موبايل الطلب');
    const submit = page.getByRole('button', { name: 'تتبع الطلب', exact: true });
    await phone.fill(user.phone);
    await submit.click();
    const details = page.getByRole('region', { name: 'تفاصيل تتبع الطلب' });
    await details.getByRole('heading', { name: 'سجل حالة الطلب' }).waitFor();
    await phone.fill('01000000000');
    await submit.click();
    await page.getByRole('alert').filter({ hasText: 'تعذر التحقق من بيانات الطلب' }).waitFor();
    await details.waitFor({ state: 'hidden' });
  });

  await t.test('HT-ACC-04 shows empty wallet, balance, amounts, order reference and both pages', async t => {
    const empty = await open(t, browser, { destination: 'retail', empty: true });
    await empty.goto('/account/wallet');
    await empty.getByText('لا توجد حركات في المحفظة بعد.').waitFor();
    const page = await open(t, browser, { destination: 'retail' });
    await page.goto('/account/wallet');
    await page.locator('.wallet-credit').first().waitFor();
    assert.equal(await page.locator('.wallet-credit, .wallet-debit').count(), 20);
    assert.match(await page.getByRole('heading', { name: 'الرصيد' }).locator('..').innerText(), /\b180\b/);
    assert.match(await page.locator('.wallet-debit').innerText(), /-.*\b20\b/);
    await page.getByText(new Date(createdAt).toLocaleDateString('ar-EG', { timeZone: 'Africa/Cairo' }), { exact: false }).first().waitFor();
    await page.getByRole('button', { name: 'التالي' }).click();
    await page.getByText('رصيد سابق', { exact: false }).waitFor();
    await page.getByRole('button', { name: 'السابق' }).click();
    await page.getByRole('link', { name: 'طلب #1' }).click();
    await page.waitForURL(url => url.pathname === '/account/orders');
  });

  await t.test('HT-ACC-05 shows empty returns, Arabic statuses, and both pages', async t => {
    const empty = await open(t, browser, { destination: 'retail', empty: true });
    await empty.goto('/account/returns');
    await empty.getByText('لا توجد طلبات إرجاع حتى الآن.').waitFor();
    const page = await open(t, browser, { destination: 'retail' });
    await page.goto('/account/returns');
    for (const status of ['قيد المراجعة', 'تمت الموافقة', 'مرفوض', 'تم استلام المنتجات', 'تم رد المبلغ', 'ملغي']) {
      await page.locator('summary').getByText(status, { exact: true }).first().waitFor();
    }
    assert.equal(await page.locator('main summary').count(), 20);
    await page.getByRole('button', { name: 'التالي' }).click();
    await page.getByText('RET-1021', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'السابق' }).click();
    await page.getByText('RET-1001', { exact: true }).waitFor();
  });
});
