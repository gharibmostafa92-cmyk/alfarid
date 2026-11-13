const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const source = ts.transpileModule(fs.readFileSync(path.join(__dirname, '../apps/storefront/src/lib/core/server-branding.ts'), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const defaults = { name: 'Alfarid', logo: null, email: null, phone: null, address: null };

function loadServerBranding(fetch, env = {}, cloudRunIdentityToken = async () => {
  assert.fail('Public branding requests must not ask for a Cloud Run identity token');
}, timeoutLog = []) {
  const wrappedAbortSignal = {
    timeout(ms) {
      timeoutLog.push(ms);
      return AbortSignal.timeout(ms);
    },
  };
  const moduleObject = { exports: {} };
  const dependencies = {
    react: { cache: callback => callback },
    '@alfarid/ui/brand-identity': { DEFAULT_BRANDING: defaults },
    '@/lib/catalog/store-image': { storeImageUrl: logo => logo },
    '@/lib/core/cloud-run-api': { cloudRunIdentityToken },
  };
  new Function('exports', 'module', 'require', 'fetch', 'process', 'AbortSignal', source)(
    moduleObject.exports, moduleObject,
    name => {
      assert.ok(Object.hasOwn(dependencies, name), `Unexpected dependency: ${name}`);
      return dependencies[name];
    },
    fetch, { env }, wrappedAbortSignal,
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
  assert.equal(String(request.url), 'http://127.0.0.1:8000/api/v1/store/branding');
  assert.deepEqual(request.options.next, { revalidate: 60, tags: ['branding'] });
  assert.equal(request.options.headers.get('Authorization'), null);
  assert.equal(request.options.headers.get('X-Serverless-Authorization'), null);
  assert.ok(request.options.signal instanceof AbortSignal);
});

test('server branding supports an internal API address independent of the browser address', async () => {
  let requestedUrl;
  const get = loadServerBranding(async url => {
    requestedUrl = url;
    return { ok: true, json: async () => ({ data: { ...defaults, name: 'Custom Store' } }) };
  }, { API_INTERNAL_URL: 'http://backend:8000/', NEXT_PUBLIC_API_URL: 'https://api.example.com' });

  assert.equal((await get()).name, 'Custom Store');
  assert.equal(String(requestedUrl), 'http://backend:8000/api/v1/store/branding');
});

test('private Cloud Run branding sends a service identity to the private origin and caps timeout at 1500ms', async () => {
  let request;
  const audiences = [];
  const timeouts = [];
  const get = loadServerBranding(async (url, options) => {
    request = { url, options };
    return { ok: true, json: async () => ({ data: { ...defaults, name: 'Private Origin Store' } }) };
  }, {
    PRIVATE_CLOUD_RUN_API_ORIGIN: 'https://private-api.run.app/',
    API_INTERNAL_URL: 'http://backend:8000/',
  }, async audience => {
    audiences.push(audience);
    return 'service-token';
  }, timeouts);

  assert.equal((await get()).name, 'Private Origin Store');
  assert.deepEqual(audiences, ['https://private-api.run.app']);
  assert.deepEqual(timeouts, [1500]);
  assert.equal(String(request.url), 'https://private-api.run.app/api/v1/store/branding');
  assert.equal(request.options.headers.get('X-Serverless-Authorization'), 'Bearer service-token');
  assert.equal(request.options.headers.get('Authorization'), null);
  assert.equal(request.options.redirect, 'error');
});

test('StoreBrandingProvider with initialBranding skips redundant client fetch on initial mount', () => {
  const providerSource = ts.transpileModule(
    fs.readFileSync(path.join(__dirname, '../packages/ui/src/components/branding-provider.tsx'), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } },
  ).outputText;
  const storeProviderSource = ts.transpileModule(
    fs.readFileSync(path.join(__dirname, '../apps/storefront/src/providers/store-branding-provider.tsx'), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } },
  ).outputText;

  const effects = [];
  let fetchCalls = 0;
  const reactMock = {
    createContext: v => ({ Provider: ({ children }) => children, _default: v }),
    useContext: () => ({ branding: defaults, refreshBranding: async () => {} }),
    useState: init => [init, () => {}],
    useRef: init => ({ current: init }),
    useCallback: fn => fn,
    useEffect: fn => { effects.push(fn); },
  };
  const jsxRuntime = {
    jsx: (type, props) => (typeof type === 'function' ? type(props) : { type, props }),
    Fragment: ({ children }) => children,
  };
  const brandMod = { exports: {} };
  new Function('exports', 'module', 'require', providerSource)(brandMod.exports, brandMod, name => {
    if (name === 'react') return reactMock;
    if (name === 'react/jsx-runtime') return jsxRuntime;
    if (name === '../lib/brand-identity') return { DEFAULT_BRANDING: defaults };
    throw new Error('Unexpected dep: ' + name);
  });

  const storeMod = { exports: {} };
  new Function('exports', 'module', 'require', storeProviderSource)(storeMod.exports, storeMod, name => {
    if (name === 'react/jsx-runtime') return jsxRuntime;
    if (name === '@alfarid/ui/branding') return brandMod.exports;
    if (name === '@alfarid/api-client') return { brandingEndpoints: { get: async () => { fetchCalls++; return defaults; } } };
    if (name === '@/lib/catalog/store-image') return { storeImageUrl: v => v };
    throw new Error('Unexpected dep: ' + name);
  });

  const savedWindow = globalThis.window;
  const savedDocument = globalThis.document;
  globalThis.window = { addEventListener: () => {}, removeEventListener: () => {} };
  globalThis.document = { visibilityState: 'visible', addEventListener: () => {}, removeEventListener: () => {} };
  try {
    storeMod.exports.StoreBrandingProvider({ initialBranding: { ...defaults, name: 'SSR Brand' }, children: null });
    for (const eff of effects) {
      const cleanup = eff();
      if (typeof cleanup === 'function') cleanup();
    }
    assert.equal(fetchCalls, 0);
  } finally {
    if (savedWindow === undefined) delete globalThis.window; else globalThis.window = savedWindow;
    if (savedDocument === undefined) delete globalThis.document; else globalThis.document = savedDocument;
  }
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
