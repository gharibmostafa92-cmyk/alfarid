const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

(async () => {
  const source = fs.readFileSync(path.resolve(__dirname, '../packages/api-client/src/endpoints/operations/http.ts'), 'utf8');
  const compiled = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const requests = [];
    await page.route('https://attachments.example.test/**', route => {
      requests.push(route.request().resourceType());
      return route.fulfill({ body: 'private receipt', contentType: 'application/pdf',
        headers: { 'content-disposition': 'attachment; filename="receipt.pdf"' } });
    });
    await page.setContent('<!doctype html><html><body>Attachment download test</body></html>');
    await page.evaluate(code => {
      const exports = {};
      const api = { get: async (_url, config) => {
        if (config.params.signed !== 1) throw new Error('Missing signed URL negotiation');
        return { data: new Blob([JSON.stringify({ url: 'https://attachments.example.test/receipt?signed=test' })]),
          headers: { 'content-type': 'application/json' } };
      } };
      new Function('exports', 'require', code)(exports, () => ({ defaultApiClient: api }));
      window.testFiles = exports;
    }, compiled);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.evaluate(() => window.testFiles.downloadFile('/private-receipt', 'fallback.pdf')),
    ]);
    assert.equal(download.suggestedFilename(), 'receipt.pdf');
    assert.equal(await download.failure(), null);
    assert.deepEqual(requests, ['document'], 'R2 transfer must use browser navigation, not XHR');
    assert.deepEqual(errors, []);
    console.log('PASS: signed attachment downloaded through browser navigation; no page errors.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
