const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage();
    await page.addInitScript(() => {
      window.assetViolations = [];
      document.addEventListener('securitypolicyviolation', event => {
        if (/font-awesome|webfonts/.test(event.blockedURI)) window.assetViolations.push(event.blockedURI);
      });
    });
    await page.goto(process.env.STOREFRONT_URL || 'http://localhost:3001', { waitUntil: 'domcontentloaded', timeout: 60000 });
    const icons = await page.evaluate(async () => {
      const fonts = await document.fonts.load('900 16px "Font Awesome 6 Free"', '\uf290');
      return { count: fonts.length, violations: window.assetViolations };
    });
    assert.deepEqual(icons.violations, [], 'Icon stylesheet and fonts must satisfy CSP');
    assert.ok(icons.count > 0, 'Font Awesome solid font must load');
    const favicon = await page.request.get(new URL('/favicon.ico', page.url()).href);
    assert.equal(favicon.status(), 200, 'Browser favicon request must succeed');
    console.log('Storefront icon font, CSP and favicon checks passed');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
