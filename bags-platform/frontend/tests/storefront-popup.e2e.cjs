const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.env.STOREFRONT_URL || 'http://127.0.0.1:3001';
const popupPath = '/popup-test.svg';
const popup = { id: 1, position: 'popup', title: 'Test offer', image: popupPath };
const hero = { id: 2, position: 'main_slider', title: 'Test hero', image: '/hero-test.svg',
  href: 'https://example.test/offer', is_external: true, cta_label: 'Open test offer' };
const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80"/></svg>';

async function testPage(browser, imageHandler) {
  const page = await browser.newPage();
  await page.clock.install();
  await page.route(`**${popupPath}`, imageHandler);
  await page.route('**/hero-test.svg', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
  await page.route('**/api/v1/store/**', route => {
    const headers = { 'access-control-allow-origin': '*' };
    const isHome = new URL(route.request().url()).pathname.endsWith('/home');
    return route.fulfill({ headers, json: { data: isHome ? { banners: [hero, popup] } : [] } });
  });
  return page;
}

async function verifyPopup() {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    let releaseImage;
    const gate = new Promise(resolve => { releaseImage = resolve; });
    const page = await testPage(browser, async route => {
      await gate;
      await route.fulfill({ contentType: 'image/svg+xml', body: svg });
    });
    const requested = page.waitForRequest(request => request.url().endsWith(popupPath));
    await page.goto(base, { waitUntil: 'domcontentloaded' });
    await requested;
    const dialog = page.getByRole('dialog', { name: popup.title });
    const link = page.locator('a[href="https://example.test/offer"]');
    await link.waitFor();
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await link.getAttribute('rel'), 'noopener noreferrer');
    await page.clock.runFor(2500);
    assert.equal(await dialog.count(), 0, 'Elapsed time alone must not display the offer');
    releaseImage();
    await dialog.waitFor();
    await dialog.getByRole('button', { name: 'Close offer' }).click();
    await page.reload();
    await link.waitFor();
    await page.clock.runFor(3000);
    assert.equal(await dialog.count(), 0, 'The dismissed offer stays hidden in the same session');

    const broken = await testPage(browser, route => route.abort('failed'));
    const failedImage = broken.waitForEvent('requestfailed', request => request.url().endsWith(popupPath));
    await broken.goto(base);
    await failedImage;
    await broken.clock.runFor(3000);
    assert.equal(await broken.getByRole('dialog', { name: popup.title }).count(), 0);
    console.log('Storefront browser test passed: safe external hero link, popup image gating, and dismissal.');
  } finally {
    await browser.close();
  }
}

verifyPopup().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
