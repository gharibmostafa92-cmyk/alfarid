const { test } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const office = process.env.E2E_BACKOFFICE_URL || 'http://localhost:5173';
const web = process.env.E2E_WEB_URL || 'http://localhost:3001';
const branch = { id: 1, name: 'Storefront Demo Warehouse', code: 'DEMO-WEB' };
const cashier = {
  id: 42, name: 'Test cashier', roles: ['cashier'], permissions: ['pos.access', 'pos.sales.create'],
  branch_id: branch.id, branch,
};

test('verified POS sessions distinguish branch access from drawer availability', async (t) => {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || 'msedge' });
  t.after(() => browser.close());

  for (const scenario of ['guest', 'expired', 'no-branch', 'branch-revoked', 'limited-staff', 'no-drawer', 'empty-drawer', 'funded-drawer']) {
    await t.test(scenario, async () => {
      const page = await browser.newPage();
      try {
        const token = scenario === 'guest' ? null : 'pos-test-session';
        const balance = scenario === 'funded-drawer' ? '125.50' : '0.00';
        const drawers = scenario.endsWith('-drawer') && scenario !== 'no-drawer'
          ? [{ id: 8, name: 'Test drawer', balance, busy: false }] : [];
        const openings = [];
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
            return reply({ data: { branch, user: cashier, drawers, categories: [], settings: {} } });
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
        const branchUnavailable = ['no-branch', 'branch-revoked'].includes(scenario);
        if (scenario === 'guest' || scenario === 'expired' || branchUnavailable) {
          await page.waitForURL(web + '/auth/login' + (branchUnavailable ? '?error=branch' : ''));
          assert.equal(logouts, branchUnavailable ? 1 : 0);
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
