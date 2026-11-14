'use strict';

// Run with Playwright Chromium installed: node --test tests/pos-inventory-f13.e2e.cjs

const { before, after, test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../packages/pos-core');
const scripts = {
  '/inventory-test.js': `
    import React from 'react';
    import {createRoot} from 'react-dom/client';
    import {TransfersPage} from '/src/components/inventory/TransfersPage.tsx';
    import {StocktakePage} from '/src/components/inventory/StocktakePage.tsx';
    import {StockPage} from '/src/components/inventory/StockPage.tsx';
    import {fixture} from 'inventory-pos';

    const screens = {transfers: TransfersPage, stocktake: StocktakePage, stock: StockPage};
    const root = createRoot(document.getElementById('root'));
    const Screen = screens[new URLSearchParams(location.search).get('screen')];
    fixture.render = () => root.render(React.createElement(React.StrictMode, null, React.createElement(Screen, {
      toast: (message, tone = 'ok') => fixture.toasts.push({message, tone}),
    })));
    fixture.unmount = () => root.unmount();
    fixture.render();
  `,
  'inventory-pos': `
    const product = {
      id: 10, name: 'حقيبة', sku: 'BAG', barcode: '101', cat: 'hand',
      price: 4, wholesale: 3, stock: 0.5, reorder: 2, h: 0,
    };
    const transfer = {
      id: 15, version: 1, number: 'TRF-15', from: 'المخزن', to: 'الفرع',
      shipped: 'أمس', driver: 'سائق', status: 'shipped',
      items: [{id: product.id, transferItemId: 155, qty: 5}],
    };
    const session = {id: 19, code: 'ST-19', status: 'draft', counts: {}, log: [],
      lastScan: {id: product.id, qty: 1, via: 'barcode', name: product.name}};
    const listeners = new Set();
    export const fixture = window.fixture = {
      toasts: [], requests: [], scans: [], transferLoads: 0, transferResponses: 0, sourceLoads: 0,
      transfer, transfers: [transfer], session, sourceFailed: false, failNextScan: false,
      holdTransferLoad: false,
      notify: () => listeners.forEach((listener) => listener()),
    };
    window.SpeechRecognition = class {
      constructor() { fixture.speech = this; }
      start() {}
      abort() { this.aborted = true; this.onend?.(); }
    };
    export const pos = {
      subscribe(listener) {
        listeners.add(listener);
        return () => listeners.delete(listener);
      },
      async getTransfersIn() {
        fixture.transferLoads++;
        const result = structuredClone(fixture.transfers);
        if (fixture.holdTransferLoad) {
          fixture.holdTransferLoad = false;
          await new Promise((resolve) => { fixture.releaseTransferLoad = resolve; });
        }
        fixture.transferResponses++;
        return result;
      },
      async receiveTransfer(id) {
        fixture.transfers = fixture.transfers.filter((transfer) => transfer.id !== id);
      },
      async getProducts() { return [product]; },
      getProductById(id) { return id === product.id ? product : undefined; },
      async getTransferSources() {
        fixture.sourceLoads++;
        if (fixture.sourceFailed) throw new Error('offline');
        return [{id: 7, name: 'المصدر', kind: 'warehouse'}];
      },
      async requestTransfer(source, items, note) {
        fixture.requests.push({source, items, note});
      },
      async getStockPage() { return {rows: [], lastPage: 1}; },
      async getStocktakeSession() {
        return {session: structuredClone(fixture.session), scope: [product]};
      },
      async recordStocktakeScan(productId, qty, source, words, id) {
        const duplicate = fixture.scans.some((scan) => scan.id === id);
        fixture.scans.push({productId, qty, source, words, id});
        if (fixture.failNextScan) {
          fixture.failNextScan = false;
          throw new Error('response lost');
        }
        return {duplicate, product_name: product.name};
      },
    };
  `,
};

let server;
let browser;

before(async () => {
  const vitePath = require.resolve('vite', { paths: [root] });
  const { createServer } = await import(pathToFileURL(vitePath).href);
  server = await createServer({
    root,
    configFile: false,
    define: { 'import.meta.env.DEV': false },
    optimizeDeps: { include: ['react', 'react-dom', 'react-dom/client', 'react/jsx-dev-runtime'] },
    server: { host: '127.0.0.1', port: 0, hmr: false },
    plugins: [{
      name: 'pos-inventory-test',
      enforce: 'pre',
      resolveId(id) {
        if (id.endsWith('/pos-data-source')) return '\0inventory-pos';
        if (Object.hasOwn(scripts, id)) return '\0' + id;
      },
      load(id) { return scripts[id.slice(1)]; },
      configureServer({ middlewares }) {
        middlewares.use((req, res, next) => {
          if (!req.url.startsWith('/inventory-test?')) return next();
          res.setHeader('content-type', 'text/html');
          res.end('<!doctype html><div id="root"></div><script type="module" src="/inventory-test.js"></script>');
        });
      },
    }],
  });
  await server.listen();
  browser = await chromium.launch();
});

after(async () => {
  await browser?.close();
  await server?.close();
});

async function screen(t, name) {
  const page = await browser.newPage();
  t.after(() => page.close());
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  t.after(() => assert.deepEqual(errors, []));
  const { port } = server.httpServer.address();
  await page.goto(`http://127.0.0.1:${port}/inventory-test?screen=${name}`);
  return page;
}

async function requestTab(page) {
  await page.getByRole('button', { name: 'طلب تحويل وارد للفرع' }).click();
  await page.getByRole('button', { name: /حقيبة/ }).click();
}

test('incoming count and receipt share one list, even without a service notification', async (t) => {
  const page = await screen(t, 'transfers');
  const count = page.locator('.tab-count');
  const receipt = page.getByRole('button', { name: /تأكيد الاستلام/ });
  await receipt.waitFor();
  assert.equal(await page.evaluate(() => fixture.transferLoads), 1);
  await receipt.click();
  await page.getByText('لا توجد شحنات واردة').waitFor();
  assert.equal(await count.textContent(), '٠');
});

test('an incoming-list refresh preserves edited receipt quantities', async (t) => {
  const page = await screen(t, 'transfers');
  const amount = page.locator('.qty-input').first();
  await amount.fill('3');
  await page.evaluate(() => {
    fixture.transfers[0].driver = 'السائق الجديد';
    fixture.render();
    fixture.notify();
  });
  await page.getByText(/السائق الجديد/).waitFor();
  assert.equal(await amount.inputValue(), '3');
});

test('a stale incoming snapshot cannot restore a received shipment', async (t) => {
  const page = await screen(t, 'transfers');
  const receipt = page.getByRole('button', { name: /تأكيد الاستلام/ });
  await receipt.waitFor();
  await page.evaluate(() => {
    fixture.holdTransferLoad = true;
    fixture.notify();
  });
  await page.waitForFunction(() => fixture.releaseTransferLoad);
  await receipt.click();
  await page.getByText('لا توجد شحنات واردة').waitFor();
  await page.evaluate(() => fixture.releaseTransferLoad());
  await page.waitForFunction(() => fixture.transferResponses === 2);
  assert.equal(await page.locator('.tab-count').textContent(), '٠');
});

test('a failed source lookup leaves transfer submission disabled', async (t) => {
  const page = await screen(t, 'transfers');
  await page.evaluate(() => { fixture.sourceFailed = true; });
  await requestTab(page);
  await page.waitForFunction(() => fixture.toasts.some((toast) => toast.tone === 'bad'));
  assert.equal(await page.getByRole('button', { name: 'إرسال طلب التحويل' }).isDisabled(), true);
});

test('transfer suggestions stay whole and rapid clicks send one request', async (t) => {
  const page = await screen(t, 'transfers');
  await requestTab(page);
  await page.getByRole('radio', { name: 'المصدر' }).waitFor();
  await page.evaluate(() => fixture.render());
  await page.getByRole('button', { name: 'إرسال طلب التحويل' }).evaluate((button) => {
    button.click();
    button.click();
  });
  await page.waitForFunction(() => fixture.requests.length > 0);
  assert.deepEqual(await page.evaluate(() => fixture.requests), [
    { source: 7, items: [{ id: 10, qty: 4 }], note: '' },
  ]);
  assert.equal(await page.evaluate(() => fixture.sourceLoads), 1);
});

test('a lost stocktake response can be retried explicitly and the next identical scan is new', async (t) => {
  const page = await screen(t, 'stocktake');
  const barcode = page.getByPlaceholder('امسح باركود الصنف');
  await barcode.fill('101');
  await page.evaluate(() => { fixture.failNextScan = true; });
  await barcode.press('Enter');
  const retry = page.getByRole('button', { name: 'إعادة محاولة التسجيل' });
  await retry.click({ timeout: 1500 });
  await retry.waitFor({ state: 'hidden' });
  assert.equal(await page.evaluate(() => fixture.toasts.at(-1).tone), 'ok');
  await barcode.fill('101');
  await barcode.press('Enter');
  await page.waitForFunction(() => fixture.scans.length === 3);
  const [first, second, third] = await page.evaluate(() => fixture.scans);
  assert.equal(first.id, second.id);
  assert.notEqual(second.id, third.id);
});

test('changing stocktake sessions detaches and aborts pending speech recognition', async (t) => {
  const page = await screen(t, 'stocktake');
  await page.getByRole('button', { name: 'الإدخال الصوتي' }).click();
  await page.evaluate(() => {
    fixture.session = { ...fixture.session, id: 20, code: 'ST-20' };
    fixture.notify();
  });
  await page.getByText('ST-20').waitFor();
  const outcome = await page.evaluate(async () => {
    await fixture.speech.onresult?.({ results: [[{ transcript: 'خمسة' }]] });
    return { aborted: fixture.speech.aborted, scans: fixture.scans.length };
  });
  assert.deepEqual(outcome, { aborted: true, scans: 0 });
});

test('speech completion uses the latest product in the same session', async (t) => {
  const page = await screen(t, 'stocktake');
  await page.getByRole('button', { name: 'الإدخال الصوتي' }).click();
  await page.evaluate(() => {
    fixture.session.lastScan = { id: 11, qty: 1, via: 'barcode', name: 'المحفظة' };
    fixture.notify();
  });
  await page.getByText('المحفظة').waitFor();
  await page.evaluate(() => fixture.speech.onresult?.({ results: [[{ transcript: 'خمسة' }]] }));
  await page.waitForFunction(() => fixture.scans.length === 1);
  const [scan] = await page.evaluate(() => fixture.scans);
  assert.equal(scan.productId, 11);
  assert.equal(scan.words, 'خمسة');
  assert.equal(await page.evaluate(() => fixture.speech.aborted), true);
});

test('production inventory screens omit developer API and permission labels', async (t) => {
  const transfers = await screen(t, 'transfers');
  await transfers.getByRole('button', { name: /تأكيد الاستلام/ }).waitFor();
  assert.equal(await transfers.getByText('transfers.receive').count(), 0);
  await requestTab(transfers);
  assert.equal(await transfers.getByText(/transfers.create/).count(), 0);

  const stocktake = await screen(t, 'stocktake');
  await stocktake.getByPlaceholder('امسح باركود الصنف').waitFor();
  assert.equal(await stocktake.getByText('/pos/stocktakes/19/scan').count(), 0);

  const stock = await screen(t, 'stock');
  await stock.getByRole('heading', { name: 'مخزون الفرع' }).waitFor();
  assert.equal(await stock.getByText(/^\/pos\/stock\?/).count(), 0);
});
