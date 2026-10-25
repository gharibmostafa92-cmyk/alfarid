const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const source = ts.transpileModule(fs.readFileSync(path.join(__dirname, '../apps/storefront/src/lib/server-branding.ts'), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const defaults = { name: 'Alfarid', logo: null, email: null, phone: null, address: null };

function loadServerBranding(fetch, env = {}) {
  const moduleObject = { exports: {} };
  new Function('exports', 'module', 'require', 'fetch', 'process', 'AbortSignal', source)(
    moduleObject.exports, moduleObject,
    name => name === 'react' ? { cache: callback => callback } : { DEFAULT_BRANDING: defaults },
    fetch, { env }, AbortSignal,
  );
  return moduleObject.exports.getServerBranding;
}

test('server metadata caches public identity for one minute with an invalidation tag', async () => {
  let request;
  const get = loadServerBranding(async (url, options) => {
    request = { url, options };
    return { ok: true, json: async () => ({ data: { ...defaults, name: 'الفريد للحقائب' } }) };
  }, { NEXT_PUBLIC_API_URL: 'http://localhost:8000' });

  assert.equal((await get()).name, 'الفريد للحقائب');
  assert.equal(request.url, 'http://127.0.0.1:8000/api/v1/store/branding');
  assert.deepEqual(request.options.next, { revalidate: 60, tags: ['branding'] });
  assert.equal(request.options.headers.Authorization, undefined);
  assert.ok(request.options.signal instanceof AbortSignal);
});

test('server branding supports an internal API address independent of the browser address', async () => {
  let requestedUrl;
  const get = loadServerBranding(async url => {
    requestedUrl = url;
    return { ok: true, json: async () => ({ data: { ...defaults, name: 'Custom Store' } }) };
  }, { API_INTERNAL_URL: 'http://backend:8000/', NEXT_PUBLIC_API_URL: 'https://api.example.com' });

  assert.equal((await get()).name, 'Custom Store');
  assert.equal(requestedUrl, 'http://backend:8000/api/v1/store/branding');
});

test('unavailable or malformed branding falls back to Alfarid without breaking server rendering', async () => {
  for (const fetch of [
    async () => { throw new Error('Offline'); },
    async () => ({ ok: false }),
    async () => ({ ok: true, json: async () => ({ data: null }) }),
  ]) {
    assert.deepEqual(await loadServerBranding(fetch)(), defaults);
  }
  assert.equal((await loadServerBranding(async () => ({ ok: true, json: async () => ({ data: { name: '  ' } }) }))()).name, 'Alfarid');
});
