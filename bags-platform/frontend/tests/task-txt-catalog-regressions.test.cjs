const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
const { File } = require('node:buffer');

function loadAdminUtil(filename, globals = {}) {
  const absolute = path.resolve(__dirname, '../apps/backoffice/src', filename);
  const mod = new Module(absolute, module);
  mod.filename = absolute;
  mod.paths = Module._nodeModulePaths(path.dirname(absolute));
  const js = ts.transpileModule(fs.readFileSync(absolute, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  mod._compile(js, absolute);
  return { ...mod.exports, ...globals };
}

test('CSS image URLs cannot escape the quoted url() value or use executable schemes', () => {
  const { safeCssUrl } = loadAdminUtil('pages/admin/safe-css-url.ts');
  assert.equal(safeCssUrl('javascript:alert(1)'), null);
  assert.equal(safeCssUrl('data:text/html,evil'), null);
  const safe = safeCssUrl('https://cdn.example/a");background:url(https://evil.example/x)');
  assert.ok(safe.startsWith('url("https://cdn.example/'));
  assert.ok(!safe.includes('");background'));
  assert.match(safeCssUrl('blob:https://app.example/id'), /^url\("blob:/);
});

test('product snapshots preserve File identity and ignore upload-only metadata', () => {
  const { cloneProductState, productFingerprint } = loadAdminUtil('pages/admin/catalog/product-state.ts');
  const file = new File(['same bytes'], 'photo.png', { type: 'image/png', lastModified: 10 });
  const product = { createKey: 'retry-key', rows: [{ id: 'a', price: '10' }], media: { general: [{ file, status: 'queued', progress: 0, clientUploadId: 'x' }] } };
  const original = cloneProductState(product);
  assert.notEqual(original, product);
  assert.notEqual(original.rows, product.rows);
  assert.equal(original.media.general[0].file, file);
  const fingerprint = productFingerprint(product);
  const uploaded = cloneProductState(product);
  uploaded.createKey = 'rotated-retry-key';
  uploaded.media.general[0].status = 'done';
  uploaded.media.general[0].progress = 100;
  uploaded.media.general[0].clientUploadId = 'y';
  assert.equal(productFingerprint(uploaded), fingerprint);
  uploaded.media.general[0].file = new File(['same bytes'], 'photo.png', { type: 'image/png', lastModified: 10 });
  assert.notEqual(productFingerprint(uploaded), fingerprint);
});

test('client id generation falls back when randomUUID is unavailable', () => {
  const { createClientRequestId } = loadAdminUtil('lib/idempotency.js');
  const id = createClientRequestId({ getRandomValues(bytes) { bytes.fill(7); return bytes; } });
  assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
});
