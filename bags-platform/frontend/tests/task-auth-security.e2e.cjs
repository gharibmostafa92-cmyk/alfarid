const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

// Run against two isolated dev servers configured to point at each other.
const web = process.env.STOREFRONT_URL || 'http://localhost:3003';
const office = process.env.BACKOFFICE_URL || 'http://localhost:5174';
const foreign = 'http://localhost:4199';
let browser;
before(async () => { browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' }); });
after(async () => { await browser?.close(); });

test('task 25: session endpoint rejects invalid requests and bounds its cookie', async () => {
  const context = await browser.newContext({ permissions: ['local-network-access'] });
  try {
    const token = `1|${'a'.repeat(40)}`; // Synthetic session material only.
    const request = context.request;
    const cases = [
      ['plain text', { headers: { 'content-type': 'text/plain' }, data: '{}' }, 415],
      ['foreign origin', { headers: { origin: foreign }, data: { token } }, 403],
      ['cross-site', { headers: { 'sec-fetch-site': 'cross-site' }, data: { token } }, 403],
      ['invalid token', { data: { token: 'abc' } }, 400],
      ...[0, 7201, 1.5, -1, '120', null].map(expiresIn => [`expiry ${expiresIn}`, { data: { token, expiresIn } }, 400]),
      ['malformed JSON', { headers: { 'content-type': 'application/json' }, data: '{' }, 400],
    ];
    for (const [label, options, status] of cases) {
      const response = await request.post(`${web}/api/auth`, options);
      assert.equal(response.status(), status, label);
      assert.equal(response.headers()['set-cookie'], undefined, label);
    }
    for (const expiresIn of [1, 7200, undefined]) {
      const response = await request.post(`${web}/api/auth`, { headers: { origin: web }, data: { token, expiresIn } });
      assert.equal(response.status(), 200);
      const cookie = response.headers()['set-cookie'];
      assert.match(cookie, /auth_token=/);
      assert.match(cookie, /HttpOnly/i);
      assert.match(cookie, /SameSite=Lax/i);
      assert.match(cookie, /Path=\//i);
      assert.match(cookie, new RegExp(`Max-Age=${expiresIn ?? 7200}(?:;|$)`, 'i'));
    }
    const deleted = await request.delete(`${web}/api/auth`);
    assert.equal(deleted.status(), 200);
    assert.match(deleted.headers()['set-cookie'], /auth_token=;/);
    assert.match(deleted.headers()['set-cookie'], /Expires=Thu, 01 Jan 1970/i);
  } finally { await context.close(); }
});

async function stubPublicApi(context) {
  await context.route('**/api/v1/**', route => route.fulfill({
    headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' },
    json: { data: new URL(route.request().url()).pathname.endsWith('/branding') ? { name: 'Alfarid', logo: null } : [] },
  }));
}

for (const authenticated of [false, true]) {
  test(`task 27: real session probe restricts ${authenticated ? 'active' : 'absent'} session replies to the storefront`, async () => {
    const context = await browser.newContext({ permissions: ['local-network-access'] });
    try {
      if (authenticated) await context.addInitScript(office => {
        if (location.origin === office) localStorage.setItem('backoffice_token', `1|${'x'.repeat(40)}`);
      }, office);
      await stubPublicApi(context);
      const html = `<!doctype html><script>window.messages=[];addEventListener('message',e=>messages.push({origin:e.origin,data:e.data}));</script><iframe src="${office}/auth/session#nonce=probe-test"></iframe>`;
      await context.route(`${web}/probe-test`, route => route.fulfill({ contentType: 'text/html', body: html }));
      await context.route(`${foreign}/probe-test`, route => route.fulfill({ contentType: 'text/html', body: html }));
      const page = await context.newPage();
      await page.goto(`${web}/probe-test`);
      await page.waitForFunction(() => window.messages.length > 0);
      const expected = {
        origin: office, data: { type: 'alfarid:staff-session', nonce: 'probe-test', authenticated },
      };
      const messages = await page.evaluate(() => window.messages);
      assert.ok(messages.length > 0);
      for (const message of messages) assert.deepEqual(message, expected);
      await page.goto(`${foreign}/probe-test`);
      const frame = await page.waitForSelector('iframe');
      await (await frame.contentFrame()).waitForLoadState('networkidle');
      // The positive control above proves the probe executes; the foreign parent
      // must receive nothing after its effect and development StrictMode replay.
      await page.waitForTimeout(1000);
      assert.deepEqual(await page.evaluate(() => window.messages), []);
    } finally { await context.close(); }
  });
}

for (const attack of ['nonce', 'origin', 'source']) {
  test(`task 27: login ignores a forged ${attack} and enables its form after five seconds`, async () => {
    const context = await browser.newContext({ permissions: ['local-network-access'] });
    try {
      await stubPublicApi(context);
      await context.route(`${office}/auth/session**`, route => {
        // Fragments do not reach the server: read the challenge in the frame.
        const payload = `const nonce=new URLSearchParams(location.hash.slice(1)).get('nonce');parent.postMessage({type:'alfarid:staff-session',nonce:${attack === 'nonce' ? "'wrong-nonce'" : 'nonce'},authenticated:true},${JSON.stringify(web)});`;
        const body = `<script>${attack === 'nonce' ? payload : ''}</script>`;
        return route.fulfill({ contentType: 'text/html', body });
      });
      await context.route(`${foreign}/forged**`, route => route.fulfill({ contentType: 'text/html', body: `<script>opener.postMessage({type:'alfarid:staff-session',nonce:new URLSearchParams(location.hash.slice(1)).get('nonce'),authenticated:true},${JSON.stringify(web)})</script>` }));
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(() => { window.attackReceived = false; addEventListener('message', event => {
        if (event.data?.type === 'alfarid:staff-session') window.attackReceived = true;
      }); });
      await page.goto(`${web}/auth/login`);
      const button = page.locator('form button[type=submit]');
      await button.waitFor();
      if (attack === 'origin') {
        await page.waitForSelector('iframe[title="Session verification"]');
        const popupReady = page.waitForEvent('popup');
        await page.evaluate(foreign => {
          const frame = document.querySelector('iframe[title="Session verification"]');
          window.open(`${foreign}/forged${new URL(frame.src).hash}`);
        }, foreign);
        await popupReady;
        await page.evaluate(foreign => {
          const frame = document.querySelector('iframe[title="Session verification"]');
          // Keep the expected source/nonce to exercise the origin check alone.
          dispatchEvent(new MessageEvent('message', { origin: foreign, source: frame.contentWindow,
            data: { type: 'alfarid:staff-session', nonce: new URL(frame.src).hash.slice(7), authenticated: true } }));
        }, foreign);
      }
      if (attack === 'source') {
        await page.waitForSelector('iframe[title="Session verification"]');
        await page.evaluate(office => {
          const frame = document.querySelector('iframe[title="Session verification"]');
          const nonce = new URL(frame.src).hash.slice(7);
          // A matching origin/nonce from the wrong WindowProxy is insufficient.
          dispatchEvent(new MessageEvent('message', { origin: office, source: window,
            data: { type: 'alfarid:staff-session', nonce, authenticated: true } }));
        }, office);
      }
      await page.waitForFunction(() => window.attackReceived, undefined, { timeout: 10000 });
      assert.equal(await button.isDisabled(), true);
      await button.waitFor({ state: 'visible' });
      await page.waitForFunction(() => !document.querySelector('form button[type=submit]').disabled, undefined, { timeout: 8000 });
      assert.equal(new URL(page.url()).pathname, '/auth/login');
      assert.deepEqual(errors, []);
    } finally { await context.close(); }
  });
}

test('task 27: a matching session reply from the configured frame reaches the backoffice', async () => {
  const context = await browser.newContext({ permissions: ['local-network-access'] });
  try {
    await stubPublicApi(context);
    await context.route(`${office}/auth/session**`, route => route.fulfill({ contentType: 'text/html',
      body: `<script>parent.postMessage({type:'alfarid:staff-session',nonce:new URLSearchParams(location.hash.slice(1)).get('nonce'),authenticated:true},${JSON.stringify(web)})</script>` }));
    await context.route(`${office}/dashboard`, route => route.fulfill({ contentType: 'text/html', body: '<h1>Backoffice destination</h1>' }));
    const page = await context.newPage();
    await page.goto(`${web}/auth/login`);
    await page.waitForURL(`${office}/dashboard`);
  } finally { await context.close(); }
});
