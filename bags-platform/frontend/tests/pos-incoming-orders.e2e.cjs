'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const posUrl = process.env.POS_URL || 'http://127.0.0.1:3088';

test('a committed incoming order alerts the cashier and advances the original order', async (t) => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  t.after(() => browser.close());
  const page = await browser.newPage();
  const branch = { id: 1, name: 'Cairo', code: 'CAI' };
  const user = {
    id: 42, name: 'Cashier', roles: ['cashier'], branch_id: branch.id,
    permissions: ['pos.access', 'pos.sales.create', 'orders.view', 'orders.update'],
  };
  let position = 0;
  let order;
  const commands = [];

  await page.addInitScript(() => {
    localStorage.setItem('backoffice_token', 'pos-test-session');
    window.orderTones = 0;
    class TestAudioContext {
      state = 'running';
      currentTime = 0;
      destination = {};
      createOscillator() {
        return { frequency: {}, connect: (gain) => gain, start: () => window.orderTones++, stop() {} };
      }
      createGain() {
        return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} };
      }
      close() { return Promise.resolve(); }
    }
    window.AudioContext = TestAudioContext;
  });
  await page.route('**/api/v1/**', (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const headers = {
      'access-control-allow-origin': '*',
      'access-control-allow-headers': '*',
      'access-control-allow-methods': '*',
    };
    const reply = (json) => route.fulfill({ headers, json });
    if (request.method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
    if (url.pathname.endsWith('/bootstrap')) return reply({
      data: {
        user, branch, categories: [], drawers: [], settings: {},
        shift: { id: 24, status: 'open', shift_number: 'POS-24', opened_at: new Date().toISOString(), branch },
      },
    });
    if (url.pathname.endsWith('/incoming-orders/events')) {
      const after = Number(url.searchParams.get('after'));
      return reply({
        data: after < position ? [{ order_id: position === 1 ? 51 : 52, kind: position < 3 ? 'arrival' : 'updated' }] : [],
        position, more: false, reset: false,
      });
    }
    if (url.pathname.endsWith('/incoming-orders/52/status')) {
      commands.push({ body: request.postDataJSON(), key: request.headers()['idempotency-key'] });
      order = { ...order, status: 'confirmed', next_status: 'processing' };
      position = 3;
      return reply({ data: order });
    }
    if (url.pathname.endsWith('/incoming-orders/52')) return reply({ data: order });
    if (url.pathname.endsWith('/incoming-orders')) return reply({
      data: order ? [order] : [], position, total: order ? 1 : 0, next_cursor: null, catching_up: position === 0,
    });
    return reply({ data: [] });
  });

  await page.goto(posUrl);
  await page.getByRole('button', { name: 'الطلبات (0)' }).click();
  await page.getByText('لا توجد طلبات مفتوحة.').waitFor();
  order = { id: 51, number: 'ORDER-51', channel: 'store', status: 'pending' };
  position = 1;
  await page.getByRole('button', { name: /ORDER-51/ }).waitFor();
  assert.equal(await page.evaluate(() => window.orderTones), 0);
  order = {
    id: 52, number: 'ORDER-52', channel: 'wholesale', status: 'pending', next_status: 'confirmed',
    items: [{ id: 1, name: 'Test bag', sku: 'TEST-BAG', qty: '3' }],
    delivery: { name: 'عميل الطلب', phone: '01012345678', city: 'القاهرة', address: 'عنوان التسليم', notes: 'اتصل عند الوصول' },
    payment_method: 'credit', payment_status: 'unpaid',
  };
  position = 2;
  await page.getByRole('button', { name: /ORDER-52.*تاجر/ }).click();
  await page.getByText(/Test bag.*TEST-BAG/).waitFor();
  await page.getByText(/العنوان.*عنوان التسليم/).waitFor();
  await page.getByText(/حالة الدفع.*غير مدفوع/).waitFor();
  await page.getByRole('button', { name: 'تأكيد الطلب' }).click();
  await page.getByRole('button', { name: 'بدء التجهيز' }).waitFor();
  assert.equal(await page.evaluate(() => window.orderTones), 2);
  assert.deepEqual(commands.map(({ body }) => body), [{ expected_status: 'pending', status: 'confirmed' }]);
  assert.ok(commands[0].key);
});
