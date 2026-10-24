const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const office = process.env.E2E_BACKOFFICE_URL || 'http://localhost:4173';
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const context = await browser.newContext();
    await context.addInitScript(() => {
      localStorage.setItem('backoffice_token', 'csp-review');
      window.cspViolations = [];
      document.addEventListener('securitypolicyviolation', event => window.cspViolations.push(event.violatedDirective));
    });
    await context.route('**/api/v1/admin/**', route => route.fulfill({ json: route.request().url().endsWith('/me') ? { data: {
      id: 1, name: 'Security review', roles: [{id:1,name:'Super Admin'}], permissions: ['users.view','roles.view','settings.view','reports.view','inventory.all_branches'], is_active: true,
    } } : { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(office + '/dashboard');
    assert.match(response.headers()['content-security-policy'], /frame-ancestors 'none'/);
    for (const path of ['/auth/session', '/auth/session?probe=1']) {
      const probe = await context.request.get(office + path);
      assert.match(probe.headers()['content-security-policy'], /frame-ancestors 'self' http:\/\/localhost:3000/);
    }
    await page.locator('#brand-home-btn').waitFor();
    assert.deepEqual(await page.evaluate(() => window.cspViolations), [], 'The built app must work under its policy');
    assert.deepEqual(errors, []);
    await page.evaluate(() => {
      const script = document.createElement('script');
      script.textContent = 'window.injectedScriptRan = true';
      document.body.append(script);
    });
    await page.waitForFunction(() => window.cspViolations.includes('script-src-elem'));
    assert.equal(await page.evaluate(() => window.injectedScriptRan), undefined, 'Inline injected scripts must be blocked');
    console.log('PASS: production dashboard loads and CSP blocks injected inline scripts');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
